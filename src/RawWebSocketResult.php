<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAICodex\Error\ProviderDiagnosticSanitizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Error\ProviderErrorFormatter;
use Symfony\AI\Platform\Bridge\OpenAICodex\Result\CancellableRawResultInterface;

/**
 * Codex WebSocket raw result: JSON event stream without an HTTP response envelope.
 */
final class RawWebSocketResult implements CancellableRawResultInterface
{
    private readonly CodexWebSocketResultHandle $handle;

    private bool $connectionClosed = false;

    private readonly LoggerInterface $logger;

    private bool $retainConnection = false;

    private bool $cacheFinalized = false;

    /**
     * Completed output items observed via response.output_item.done.
     *
     * Used as the continuation baseline when the terminal response omits or
     * empties response.output. Mirrors ResultConverter's stream fallback for
     * function calls so previous_response_id deltas do not re-send items the
     * provider already owns.
     *
     * @var list<array<string, mixed>>
     */
    private array $completedOutputItems = [];

    public function __construct(
        private readonly WebsocketConnection $connection,
        private readonly float $idleTimeoutSeconds,
        ?LoggerInterface $logger = null,
        private bool $aborted = false,
        private readonly ?CodexWebSocketCachedStreamContext $cachedStreamContext = null,
        private readonly ?Cancellation $cancellation = null,
    ) {
        $this->handle = new CodexWebSocketResultHandle();
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
        if (!$this->cacheFinalized) {
            $this->finalizeCachedLifecycle(false);
        }
        $this->closeConnection();
    }

    public function getData(): array
    {
        throw new \RuntimeException('Codex WebSocket results are streaming-only.');
    }

    public function getDataStream(): iterable
    {
        if ($this->aborted) {
            return;
        }

        $streamSucceeded = false;
        try {
            yield from $this->iterateEvents($streamSucceeded);
        } catch (\Throwable $e) {
            $this->finalizeCachedLifecycle(false);
            $this->closeConnection();
            throw $e;
        } finally {
            if ($streamSucceeded) {
                $this->finalizeCachedLifecycle(true);
            } else {
                $this->finalizeCachedLifecycle(false);
                $this->closeConnection();
            }
        }
    }

    public function getObject(): object
    {
        return $this->handle;
    }

    public function abort(): void
    {
        $this->aborted = true;
        $this->finalizeCachedLifecycle(false);
        $this->closeConnection();
    }

    /**
     * @return \Generator<int, array<string, mixed>, mixed, void>
     *
     * @param-out bool $streamSucceeded
     */
    private function iterateEvents(bool &$streamSucceeded): \Generator
    {
        while (!$this->aborted) {
            $this->cancellation?->throwIfRequested();
            try {
                // receive() is cancellable; idleTimeoutSeconds bounds waiting for the next
                // complete WebSocket message start. Half-closed sockets may lag isClosed()
                // until the background parser observes EOF — the timeout is the hard bound.
                $message = $this->connection->receive(
                    $this->ioCancellation(),
                );
            } catch (CancelledException $e) {
                $this->cancellation?->throwIfRequested();
                $this->logIoTimeout('receive');
                throw new \RuntimeException('Codex WebSocket idle timeout.', previous: $e);
            }

            if (null === $message) {
                throw $this->connectionClosedException();
            }

            if (!$message->isText()) {
                throw new \RuntimeException('Codex WebSocket frame was not a text message.');
            }

            // WebsocketMessage::buffer() accepts Cancellation; without it a fragmented
            // message whose continuation never arrives can block indefinitely after receive()
            // returned. Bound buffering with the same idle timeout as receive/send.
            try {
                $payload = $message->buffer($this->ioCancellation());
            } catch (CancelledException $e) {
                $this->cancellation?->throwIfRequested();
                $this->logIoTimeout('buffer');
                throw new \RuntimeException('Codex WebSocket message buffer timeout.', previous: $e);
            }

            $this->cancellation?->throwIfRequested();
            if ('' === $payload) {
                continue;
            }

            try {
                $event = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Codex WebSocket frame contained invalid JSON.', previous: $e);
            }

            if (!\is_array($event)) {
                throw new \RuntimeException('Codex WebSocket frame must decode to a JSON object.');
            }

            /* @var array<string, mixed> $event */
            yield $event;

            $type = $event['type'] ?? '';

            // Capture finalized items for both history and the continuation
            // baseline. Reasoning encrypted_content can be incomplete before
            // output_item.done; do not replace it with a terminal snapshot.
            if ('response.output_item.done' === $type && \is_array($event['item'] ?? null)) {
                $this->completedOutputItems[] = $event['item'];
            }

            if ('response.completed' === $type) {
                $this->commitContinuationIfSuccessful($event);
                $streamSucceeded = true;

                return;
            }

            if ('response.done' === $type) {
                if ($this->isSuccessfulTerminalResponse($event)) {
                    $this->commitContinuationIfSuccessful($event);
                    $streamSucceeded = true;

                    return;
                }

                throw new \RuntimeException($this->terminalErrorMessage($type, $event));
            }

            if ('response.failed' === $type || 'response.incomplete' === $type || str_starts_with((string) $type, 'error')) {
                throw new \RuntimeException($this->terminalErrorMessage($type, $event));
            }
        }
    }

    /**
     * Build a terminal error message from a failed stream event.
     *
     * @param array<string, mixed> $event
     */
    private function terminalErrorMessage(string $type, array $event): string
    {
        $error = [];
        $response = $event['response'] ?? null;
        if (\is_array($response) && \is_array($response['error'] ?? null)) {
            $error = $response['error'];
        } elseif (\is_array($event['error'] ?? null)) {
            $error = $event['error'];
        }

        $text = ProviderErrorFormatter::format($error);
        if ('' === $text) {
            return \sprintf('Codex WebSocket stream ended with non-success terminal event (%s).', $type);
        }

        // format() returns "[code/type/param]: message" when structured fields
        // exist and the bare bounded message otherwise.
        if (str_starts_with($text, '[')) {
            return $text;
        }

        return \sprintf('non-success terminal event (%s): %s', $type, $text);
    }

    private function ioCancellation(): Cancellation
    {
        $timeout = new TimeoutCancellation($this->idleTimeoutSeconds);

        return null === $this->cancellation ? $timeout : new CompositeCancellation($this->cancellation, $timeout);
    }

    private function logIoTimeout(string $phase): void
    {
        $lease = $this->cachedStreamContext?->lease;

        $this->logger->warning('codex.websocket.io_timeout', [
            'event_type' => 'codex.websocket.io_timeout',
            'component' => 'raw_websocket_result',
            'phase' => $phase,
            'timeout_seconds' => $this->idleTimeoutSeconds,
            'cache_reused' => null !== $lease && $lease->reused,
            'cache_one_shot' => null !== $lease && $lease->oneShot,
        ]);
    }

    /** Surface the provider's close code and a credential-safe reason on premature EOF. */
    private function connectionClosedException(): \RuntimeException
    {
        $closeCode = null;
        $closeReason = null;

        try {
            $info = $this->connection->getCloseInfo();
            $closeCode = $info->getCode();
            $closeReason = ProviderDiagnosticSanitizer::sanitize($info->getReason());
        } catch (\Throwable $e) {
            // receive() can return null before close metadata becomes available.
            $this->logger->debug('codex.websocket.close_info_unavailable', [
                'event_type' => 'codex.websocket.close_info_unavailable',
                'component' => 'raw_websocket_result',
                'exception_class' => $e::class,
            ]);
        }

        $this->logger->warning('codex.websocket.stream_closed', [
            'event_type' => 'codex.websocket.stream_closed',
            'component' => 'raw_websocket_result',
            'close_code' => $closeCode,
            'close_reason' => $closeReason,
        ]);

        $detail = null === $closeCode
            ? 'close info unavailable'
            : \sprintf(
                'close code %d, reason: %s',
                $closeCode,
                '' === trim($closeReason) ? '(empty)' : mb_substr(trim($closeReason), 0, 200),
            );

        return new \RuntimeException(\sprintf(
            'Codex WebSocket connection closed before response.completed (%s).',
            $detail,
        ));
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isSuccessfulTerminalResponse(array $event): bool
    {
        $response = $event['response'] ?? null;
        if (!\is_array($response)) {
            return false;
        }

        $responseId = $response['id'] ?? null;

        return \is_string($responseId) && '' !== $responseId;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function commitContinuationIfSuccessful(array $event): void
    {
        $context = $this->cachedStreamContext;
        if (null === $context || $context->lease->oneShot || null === $context->lease->entry) {
            return;
        }

        $response = $event['response'] ?? null;
        if (!\is_array($response)) {
            return;
        }

        $responseId = $response['id'] ?? null;
        if (!\is_string($responseId) || '' === $responseId) {
            return;
        }

        $responseItems = $this->resolveContinuationResponseItems($response);
        $this->logContinuationBaselineSource($response);
        $context->lease->entry->continuation = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $context->fullRequestBody,
            $responseId,
            $responseItems,
        );
    }

    /**
     * Record where the continuation baseline came from. When both sources have
     * the same number of items, compare them by position without logging data.
     * A difference here is a lead, not proof that the next history item differs.
     *
     * @param array<string, mixed> $response
     */
    private function logContinuationBaselineSource(array $response): void
    {
        $terminalOutput = $response['output'] ?? null;
        $terminalItems = \is_array($terminalOutput) ? array_values(array_filter($terminalOutput, 'is_array')) : [];
        $source = [] !== $this->completedOutputItems ? 'streamed' : 'terminal';
        $streamedCount = \count($this->completedOutputItems);
        $terminalCount = \count($terminalItems);
        $comparison = 'unavailable';
        $mismatch = null;
        $reasoningPairs = 0;
        $reasoningIdEqualPairs = 0;
        $reasoningEncryptedEqualPairs = 0;
        $reasoningEncryptedLengthMismatchPairs = 0;

        if ($terminalCount > 0 && $streamedCount > 0) {
            $comparison = 'different_count';
            if ($terminalCount === $streamedCount) {
                $mismatch = CodexWebSocketContinuationComparator::describePrefixMismatch($terminalItems, $this->completedOutputItems);
                $comparison = $mismatch['prefix_normalized_equal'] ? 'equal' : 'different';
                foreach ($terminalItems as $index => $terminalItem) {
                    $streamedItem = $this->completedOutputItems[$index] ?? null;
                    if (!\is_array($terminalItem) || !\is_array($streamedItem)) {
                        continue;
                    }
                    if ('reasoning' !== ($terminalItem['type'] ?? null) || 'reasoning' !== ($streamedItem['type'] ?? null)) {
                        continue;
                    }
                    ++$reasoningPairs;
                    $terminalId = $terminalItem['id'] ?? null;
                    $streamedId = $streamedItem['id'] ?? null;
                    if (\is_string($terminalId) && '' !== $terminalId && \is_string($streamedId) && '' !== $streamedId && $terminalId === $streamedId) {
                        ++$reasoningIdEqualPairs;
                    }
                    $terminalEncrypted = $terminalItem['encrypted_content'] ?? null;
                    $streamedEncrypted = $streamedItem['encrypted_content'] ?? null;
                    if (\is_string($terminalEncrypted) && \is_string($streamedEncrypted)) {
                        if ($terminalEncrypted === $streamedEncrypted) {
                            ++$reasoningEncryptedEqualPairs;
                        } elseif (\strlen($terminalEncrypted) !== \strlen($streamedEncrypted)) {
                            ++$reasoningEncryptedLengthMismatchPairs;
                        }
                    }
                }
            }
        }

        $this->logger->info('codex.websocket.continuation.baseline', [
            'event_type' => 'codex.websocket.continuation.baseline',
            'component' => 'raw_websocket_result',
            'baseline_source' => $source,
            'terminal_output_count' => $terminalCount,
            'streamed_done_count' => $streamedCount,
            'source_comparison' => $comparison,
            'first_mismatch_index' => $mismatch['first_mismatch_index'] ?? null,
            'left_item_kind' => $mismatch['left_item_kind'] ?? null,
            'right_item_kind' => $mismatch['right_item_kind'] ?? null,
            'mismatch_field_path' => $mismatch['mismatch_field_path'] ?? null,
            'mismatch_relation' => $mismatch['mismatch_relation'] ?? null,
            'reasoning_pair_count' => $reasoningPairs,
            'reasoning_id_equal_pair_count' => $reasoningIdEqualPairs,
            'reasoning_encrypted_equal_pair_count' => $reasoningEncryptedEqualPairs,
            'reasoning_encrypted_length_mismatch_pair_count' => $reasoningEncryptedLengthMismatchPairs,
        ]);
    }

    /**
     * Use completed response.output_item.done items, as ResultConverter does
     * for history. Fall back to terminal response.output when none were sent.
     *
     * Never merge the sources: both may contain the same output items.
     *
     * @param array<string, mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private function resolveContinuationResponseItems(array $response): array
    {
        if ([] !== $this->completedOutputItems) {
            return $this->completedOutputItems;
        }

        $output = $response['output'] ?? [];
        if (!\is_array($output)) {
            $output = [];
        }

        $items = [];
        foreach ($output as $item) {
            if (\is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function finalizeCachedLifecycle(bool $success): void
    {
        if ($this->cacheFinalized) {
            return;
        }
        $context = $this->cachedStreamContext;
        if (null === $context) {
            return;
        }
        $this->cacheFinalized = true;

        if ($success && $context->lease->cached && !$context->lease->oneShot) {
            $this->retainConnection = true;
            $context->cache->release($context->lease, true);

            return;
        }

        $context->cache->invalidateEntry($context->lease->entry, $success ? 'stream_end' : 'stream_failure');
        $this->connectionClosed = true;
    }

    /** Idempotent: stream iteration, abort(), and __destruct() all funnel here. */
    private function closeConnection(): void
    {
        if ($this->connectionClosed) {
            return;
        }

        $this->connectionClosed = true;

        if ($this->retainConnection) {
            return;
        }

        try {
            $this->connection->close();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.websocket.close_failed', [
                'event_type' => 'codex.websocket.close_failed',
                'component' => 'raw_websocket_result',
                'exception_class' => $e::class,
            ]);
        }
    }
}
