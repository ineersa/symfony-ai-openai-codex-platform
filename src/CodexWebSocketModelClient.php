<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\CancelledException;
use Amp\Http\HttpStatus;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Client\WebsocketConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;

use function Amp\async;

final class CodexWebSocketModelClient implements ModelClientInterface
{
    private const float DEFAULT_CONNECT_TIMEOUT_SECONDS = 30.0;
    private const float DEFAULT_IDLE_TIMEOUT_SECONDS = 120.0;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CodexWebSocketConnectorInterface $connector,
        private readonly CodexWebSocketUrlResolver $urlResolver,
        private readonly CodexWebSocketHandshakeHeadersFactory $handshakeHeadersFactory,
        private readonly CodexRequestBodyFactory $requestBodyFactory,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private string $accessToken,
        private readonly string $accountId,
        private readonly string $responsesPath = '/codex/responses',
        private readonly string $originator = 'symfony-ai-openai-codex',
        private readonly string $providerId = 'openai-codex',
        ?LoggerInterface $logger = null,
        /** @var (\Closure(): ?string)|null */
        private readonly ?\Closure $accessTokenRefresher = null,
        private readonly float $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        private readonly float $idleTimeoutSeconds = self::DEFAULT_IDLE_TIMEOUT_SECONDS,
        private readonly CodexTransportEnum $transport = CodexTransportEnum::Websocket,
        private readonly ?CodexWebSocketConnectionCache $connectionCache = null,
        private readonly CodexWebSocketCacheSettings $cacheSettings = new CodexWebSocketCacheSettings(),
        private readonly string $userAgent = 'symfony-ai-openai-codex',
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Model $model): bool
    {
        return $model instanceof CodexModel;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (\is_string($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, but a string was given to "%s".', self::class));
        }

        $resolution = CodexCorrelationRequestId::resolve($options, $payload);
        $websocketUrl = $this->urlResolver->resolve($this->baseUrl, $this->responsesPath);

        [$connection, $effectiveRequestId, $effectiveProvenance, $lease, $wireBody, $fullBody] = $this->prepareCachedOrFreshRequest(
            $model,
            $payload,
            $options,
            $resolution,
            $websocketUrl,
        );

        $this->logRequestSummary($model, $wireBody, $websocketUrl, $lease);

        $frame = json_encode(
            array_merge($wireBody, ['type' => 'response.create']),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        $this->sendRequestFrame($connection, $frame, $lease);

        $cachedContext = null;
        if (CodexTransportEnum::WebsocketCached === $this->transport && null !== $this->connectionCache && null !== $lease && $lease->cached && !$lease->oneShot) {
            $cachedContext = new CodexWebSocketCachedStreamContext($this->connectionCache, $lease, $fullBody);
        }

        return new RawWebSocketResult($connection, $this->idleTimeoutSeconds, $this->logger, cachedStreamContext: $cachedContext);
    }

    /**
     * Bound outbound send. Amp's WebsocketClient::sendText() has no Cancellation parameter,
     * so we await it under TimeoutCancellation. Cancellation alone does not abort the write;
     * on timeout/failure the lease/socket MUST be closed so Amp wakes the blocked writer.
     *
     * Delivery after a timed-out send is UNKNOWN: do not reconnect and resend the same
     * request (that could duplicate a provider request). A later distinct request acquires
     * a fresh connection and sends full context.
     */
    private function sendRequestFrame(
        WebsocketConnection $connection,
        string $frame,
        ?CodexWebSocketCacheLease $lease,
    ): void {
        $sendFuture = async(static function () use ($connection, $frame): void {
            $connection->sendText($frame);
        });

        try {
            $sendFuture->await(new TimeoutCancellation($this->idleTimeoutSeconds));
        } catch (CancelledException $e) {
            $this->failOutboundTransport($connection, $lease, 'send_timeout');
            // Observe the child future so a late write error is not unhandled after close.
            $sendFuture->ignore();

            $this->logger->warning('codex.websocket.io_timeout', [
                'event_type' => 'codex.websocket.io_timeout',
                'component' => 'codex_websocket_model_client',
                'phase' => 'send',
                'timeout_seconds' => $this->idleTimeoutSeconds,
                'cache_reused' => null !== $lease && $lease->reused,
                'cache_one_shot' => null !== $lease && $lease->oneShot,
                // Send started; whether the peer accepted any bytes is unknown after timeout.
                'delivery_status' => 'unknown',
            ]);

            throw new \RuntimeException('Codex WebSocket send timeout.', previous: $e);
        } catch (\Throwable $e) {
            $this->failOutboundTransport($connection, $lease, 'send_failure');
            $sendFuture->ignore();

            $this->logger->warning('codex.websocket.io_failure', [
                'event_type' => 'codex.websocket.io_failure',
                'component' => 'codex_websocket_model_client',
                'phase' => 'send',
                'timeout_seconds' => $this->idleTimeoutSeconds,
                'cache_reused' => null !== $lease && $lease->reused,
                'cache_one_shot' => null !== $lease && $lease->oneShot,
                'delivery_status' => 'failed',
                'exception_class' => $e::class,
            ]);

            throw new \RuntimeException('Codex WebSocket request frame could not be sent.', previous: $e);
        }
    }

    private function failOutboundTransport(
        WebsocketConnection $connection,
        ?CodexWebSocketCacheLease $lease,
        string $reason,
    ): void {
        if (null !== $lease && $lease->cached && !$lease->oneShot && null !== $lease->entry && null !== $this->connectionCache) {
            // invalidateEntry closes the socket and clears continuation for this session key.
            $this->connectionCache->invalidateEntry($lease->entry, $reason);

            return;
        }

        $this->closeConnectionQuietly($connection);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array{0: WebsocketConnection, 1: string, 2: CodexCorrelationProvenance, 3: ?CodexWebSocketCacheLease, 4: array<string, mixed>, 5: array<string, mixed>}
     */
    private function prepareCachedOrFreshRequest(
        Model $model,
        array $payload,
        array $options,
        CodexCorrelationResolution $resolution,
        string $websocketUrl,
    ): array {
        [$bodyPayload, $bodyOptions] = $this->normalizeBodyInputs($payload, $options, $resolution);

        $useCache = CodexTransportEnum::WebsocketCached === $this->transport
            && null !== $this->connectionCache
            && CodexCorrelationProvenance::Generated !== $resolution->provenance
            // Summarization is a separate request: a rejected summary must not
            // replace the chat continuation with the summarization response.
            && true !== ($bodyOptions[CodexRequestBodyFactory::CONTINUATION_RESET] ?? false);

        if (!$useCache) {
            [$connection, $effectiveRequestId, $effectiveProvenance] = $this->connectWithOptional401Refresh(
                $model,
                $websocketUrl,
                $resolution,
            );
            $fullBody = $this->buildFullRequestBody($model, $bodyPayload, $bodyOptions, $effectiveRequestId, $effectiveProvenance);

            return [$connection, $effectiveRequestId, $effectiveProvenance, null, $fullBody, $fullBody];
        }

        $identity = CodexWebSocketCompatibilityFingerprint::fromContext(
            $resolution->id,
            $this->providerId,
            $model->getName(),
            $this->baseUrl,
            $this->responsesPath,
            $this->accountId,
        );

        $effectiveRequestId = $resolution->id;
        $effectiveProvenance = $resolution->provenance;

        $lease = $this->connectionCache->acquire(
            $identity,
            $this->cacheSettings,
            function () use ($model, $websocketUrl, $resolution, &$effectiveRequestId, &$effectiveProvenance): WebsocketConnection {
                [$connection, $effectiveRequestId, $effectiveProvenance] = $this->connectWithOptional401Refresh(
                    $model,
                    $websocketUrl,
                    $resolution,
                );

                return $connection;
            },
        );

        $fullBody = $this->buildFullRequestBody($model, $bodyPayload, $bodyOptions, $effectiveRequestId, $effectiveProvenance);
        // Resume can choose the old baseline again even though the server's
        // effective effort changed. Do not inherit that prior response state.
        // REASONING_RESET is only set for models whose catalog entry carries
        // supports_reasoning_configuration_updates.
        // The durable generation lets each worker discard its own pre-compaction
        // continuation, even after another worker has already handled a turn.
        $generation = $bodyOptions[CodexRequestBodyFactory::CONTINUATION_GENERATION] ?? null;
        if (null !== $lease->entry && \is_int($generation)) {
            if (null !== $lease->entry->continuation && ($lease->entry->continuationGeneration ?? 0) !== $generation) {
                $lease->entry->continuation = null;
            }
            $lease->entry->continuationGeneration = $generation;
        }
        // Supported in-band reasoning epochs also start fresh.
        if (true === ($bodyOptions[CodexRequestBodyFactory::REASONING_RESET] ?? false)
            && null !== $lease->entry) {
            $lease->entry->continuation = null;
        }
        if (null !== $lease->entry?->continuation
            && $lease->entry->continuation->requiresFreshChainForTools($fullBody)) {
            $lease->entry->continuation = null;
            $this->logger->info('codex.websocket.continuation.tools_changed', [
                'event_type' => 'codex.websocket.continuation.tools_changed',
                'component' => 'codex_websocket_model_client',
            ]);
        }
        $wireBody = $this->buildWireRequestBody($lease, $fullBody);

        return [$lease->connection, $effectiveRequestId, $effectiveProvenance, $lease, $wireBody, $fullBody];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function normalizeBodyInputs(array $payload, array $options, CodexCorrelationResolution $resolution): array
    {
        return [$payload, $resolution->options];
    }

    /**
     * @param array<string, mixed> $bodyPayload
     * @param array<string, mixed> $bodyOptions
     *
     * @return array<string, mixed>
     */
    private function buildFullRequestBody(
        Model $model,
        array $bodyPayload,
        array $bodyOptions,
        string $correlationId,
        CodexCorrelationProvenance $provenance,
    ): array {
        if (CodexCorrelationProvenance::Generated === $provenance) {
            $bodyOptions['prompt_cache_key'] = $correlationId;
        }

        return $this->requestBodyFactory->build($model, $bodyPayload, $bodyOptions);
    }

    /**
     * @param array<string, mixed> $fullBody
     *
     * @return array<string, mixed>
     */
    private function buildWireRequestBody(CodexWebSocketCacheLease $lease, array $fullBody): array
    {
        $keyContext = CodexWebSocketContinuationDecision::promptCacheKeyContext($fullBody);

        if ($lease->oneShot || null === $lease->entry || null === $lease->entry->continuation) {
            $this->logger->info('codex.websocket.continuation.full_context', [
                'event_type' => 'codex.websocket.continuation.full_context',
                'component' => 'codex_websocket_model_client',
                'reason' => $lease->oneShot ? 'busy_one_shot' : 'no_continuation',
                'prompt_cache_key_present' => $keyContext['prompt_cache_key_present'],
                'prompt_cache_key_fp' => $keyContext['prompt_cache_key_fp'],
                'prompt_cache_key_length' => $keyContext['prompt_cache_key_length'],
                'current_input_count' => \is_array($fullBody['input'] ?? null) ? \count($fullBody['input']) : 0,
            ]);

            return $fullBody;
        }

        $decision = $lease->entry->continuation->decide($fullBody);
        if (null === $decision->delta) {
            $this->logger->error('codex.websocket.continuation.mismatch', [
                'event_type' => 'codex.websocket.continuation.mismatch',
                'component' => 'codex_websocket_model_client',
                ...$decision->toLogContext(),
            ]);
            $this->failOutboundTransport($lease->connection, $lease, 'continuation_mismatch');

            throw new CodexWebSocketContinuationMismatchException($decision->reason);
        }

        $delta = $decision->delta;
        $wireBody = $fullBody;
        $wireBody['previous_response_id'] = $delta['previous_response_id'];
        $wireBody['input'] = $delta['input'];
        // Keep the already-resolved prompt_cache_key: Responses accepts it with
        // previous_response_id and it identifies the same reusable prompt family.

        $this->logger->info('codex.websocket.continuation.delta', [
            'event_type' => 'codex.websocket.continuation.delta',
            'component' => 'codex_websocket_model_client',
            ...$decision->toLogContext(),
        ]);

        return $wireBody;
    }

    /**
     * @return array{0: WebsocketConnection, 1: string, 2: CodexCorrelationProvenance}
     */
    private function connectWithOptional401Refresh(
        Model $model,
        string $websocketUrl,
        CodexCorrelationResolution $resolution,
    ): array {
        $requestId = $resolution->id;
        try {
            return [
                $this->connector->connect(
                    $websocketUrl,
                    $this->buildHandshakeHeaders($requestId),
                    $this->connectTimeoutSeconds,
                ),
                $requestId,
                $resolution->provenance,
            ];
        } catch (WebsocketConnectException $e) {
            if (HttpStatus::UNAUTHORIZED !== $e->getResponse()->getStatus() || null === $this->accessTokenRefresher) {
                throw $this->toHandshakeRuntimeException($e);
            }

            $fresh = $this->refreshAccessTokenOnce($model);
            if (null === $fresh || $fresh === $this->accessToken) {
                throw $this->toHandshakeRuntimeException($e);
            }

            $this->accessToken = $fresh;
            $retryRequestId = $resolution->idFor401Retry();

            $this->logger->info('codex.token.refreshed_on_401', [
                'event_type' => 'codex.token.refreshed_on_401',
                'component' => 'codex_websocket_model_client',
                'attempt' => 1,
            ]);

            try {
                return [
                    $this->connector->connect(
                        $websocketUrl,
                        $this->buildHandshakeHeaders($retryRequestId),
                        $this->connectTimeoutSeconds,
                    ),
                    $retryRequestId,
                    $resolution->provenance,
                ];
            } catch (WebsocketConnectException $retry) {
                throw $this->toHandshakeRuntimeException($retry);
            }
        }
    }

    /**
     * Privacy-safe outgoing request summary: structural metadata only.
     *
     * @param array<string, mixed> $jsonBody
     */
    private function logRequestSummary(Model $model, array $jsonBody, string $websocketUrl, ?CodexWebSocketCacheLease $lease): void
    {
        $input = $jsonBody['input'] ?? [];
        $inputCount = \is_array($input) ? \count($input) : 0;
        $tools = $jsonBody['tools'] ?? [];

        $inputTypes = [];
        if (\is_array($input)) {
            foreach ($input as $item) {
                if (isset($item['type']) && \is_string($item['type'])) {
                    $inputTypes[$item['type']] = true;
                }
                if (isset($item['role']) && \is_string($item['role'])) {
                    $inputTypes['role:'.$item['role']] = true;
                }
            }
        }

        $requestUrlPath = parse_url($websocketUrl, \PHP_URL_PATH);
        $requestUrlPath = \is_string($requestUrlPath) && '' !== $requestUrlPath
            ? $requestUrlPath
            : $this->responsesPath;

        $this->logger->info('llm.provider.request_prepared', [
            'event_type' => 'llm.provider.request_prepared',
            'transport' => $this->transport->value,
            'request_url_path' => $requestUrlPath,
            'model' => $model->getName(),
            'body_keys' => implode(', ', array_keys($jsonBody)),
            'input_count' => $inputCount,
            'input_types' => [] !== $inputTypes ? implode(', ', array_keys($inputTypes)) : 'none',
            'tool_count' => \is_array($tools) ? \count($tools) : 0,
            'has_instructions' => isset($jsonBody['instructions']),
            'has_reasoning' => isset($jsonBody['reasoning']),
            'has_include' => isset($jsonBody['include']),
            'has_text' => isset($jsonBody['text']),
            'has_store' => isset($jsonBody['store']),
            'has_stream' => isset($jsonBody['stream']),
            'has_previous_response_id' => isset($jsonBody['previous_response_id']),
            ...CodexWebSocketContinuationDecision::promptCacheKeyContext($jsonBody),
            'cache_reused' => null !== $lease && $lease->reused,
            'cache_one_shot' => null !== $lease && $lease->oneShot,
            'originator' => $this->originator,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildHandshakeHeaders(string $requestId): array
    {
        return $this->handshakeHeadersFactory->create(
            $this->accessToken,
            $this->accountId,
            $this->originator,
            $requestId,
            $this->userAgent,
        );
    }

    private function refreshAccessTokenOnce(?Model $model): ?string
    {
        try {
            return ($this->accessTokenRefresher)();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.token.refresh_failed', [
                'event_type' => 'codex.token.refresh_failed',
                'component' => 'codex_websocket_model_client',
                'model' => $model?->getName(),
                'attempt' => 1,
                'exception_class' => $e::class,
            ]);

            return null;
        }
    }

    private function closeConnectionQuietly(WebsocketConnection $connection): void
    {
        try {
            $connection->close();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.websocket.close_failed', [
                'event_type' => 'codex.websocket.close_failed',
                'component' => 'codex_websocket_model_client',
                'exception_class' => $e::class,
            ]);
        }
    }

    private function toHandshakeRuntimeException(WebsocketConnectException $e): \RuntimeException
    {
        $status = $e->getResponse()->getStatus();

        return new \RuntimeException(\sprintf('Codex WebSocket handshake failed with HTTP %d.', $status), previous: $e);
    }
}
