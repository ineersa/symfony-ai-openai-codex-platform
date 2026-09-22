<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CodexModelClient implements ModelClientInterface
{
    private readonly HttpClientInterface $httpClient;
    private readonly LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $accessToken,
        private readonly string $accountId,
        private readonly string $path = '/codex/responses',
        private readonly string $originator = 'symfony-ai-openai-codex',
        ?LoggerInterface $logger = null,
        /** @var (\Closure(): ?string)|null */
        private readonly ?\Closure $accessTokenRefresher = null,
        private readonly CodexRequestBodyFactory $requestBodyFactory = new CodexRequestBodyFactory(),
        private readonly string $userAgent = 'symfony-ai-openai-codex',
    ) {
        $this->httpClient = $httpClient;
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
        $jsonBody = $this->requestBodyFactory->build($model, $payload, $resolution->options);

        $requestOptions = [
            'auth_bearer' => $this->accessToken,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
                'User-Agent' => $this->userAgent,
                'chatgpt-account-id' => $this->accountId,
                'originator' => $this->originator,
                'OpenAI-Beta' => 'responses=experimental',
                'session-id' => $resolution->id,
                'x-client-request-id' => $resolution->id,
            ],
            'json' => $jsonBody,
        ];

        $this->logRequestSummary($model, $jsonBody);

        // Bare HttpClient: Codex may omit text/event-stream on the response Content-Type.
        // CodexSseStream (via RawHttpResult) parses the SSE body independently of that header.
        $response = $this->httpClient->request('POST', $this->baseUrl.$this->path, $requestOptions);

        if (401 === $response->getStatusCode() && null !== $this->accessTokenRefresher) {
            $retried = $this->refreshAndRetryOnce($requestOptions, $model, $payload, $resolution, $response);
            if (null !== $retried) {
                $response = $retried;
            }
        }

        return new RawHttpResult($response, new CodexSseStream());
    }

    /**
     * On 401, force-refresh OAuth once and retry the POST with the new bearer token.
     *
     * Bounded: at most one refresh and one retry. Returns null when refresh is unavailable,
     * unchanged, or throws — caller keeps the original 401 response (uncancelled).
     *
     * @param array<string, mixed> $requestOptions
     * @param array<string, mixed> $payload
     */
    private function refreshAndRetryOnce(array $requestOptions, Model $model, array $payload, CodexCorrelationResolution $resolution, ResponseInterface $failedResponse): ?ResponseInterface
    {
        try {
            $fresh = ($this->accessTokenRefresher)();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.token.refresh_failed', [
                'event_type' => 'codex.token.refresh_failed',
                'component' => 'codex_model_client',
                'model' => $model->getName(),
                'attempt' => 1,
                'exception_class' => $e::class,
            ]);

            return null;
        }

        if (null === $fresh || $fresh === $this->accessToken) {
            return null;
        }

        $retryRequestId = $resolution->idFor401Retry();
        $retryInvocationOptions = $resolution->options;
        $retryPayload = $payload;
        if (CodexCorrelationProvenance::Generated === $resolution->provenance) {
            $retryInvocationOptions['prompt_cache_key'] = $retryRequestId;
        }

        $retryOptions = $requestOptions;
        $retryOptions['auth_bearer'] = $fresh;
        $retryOptions['headers']['session-id'] = $retryRequestId;
        $retryOptions['headers']['x-client-request-id'] = $retryRequestId;
        $retryOptions['json'] = $this->requestBodyFactory->build($model, $retryPayload, $retryInvocationOptions);

        $this->logger->info('codex.token.refreshed_on_401', [
            'event_type' => 'codex.token.refreshed_on_401',
            'component' => 'codex_model_client',
            'model' => $model->getName(),
            'attempt' => 1,
        ]);

        // Release the failed 401 connection before the retry POST
        // (mirrors RetryableHttpClient's cancel-before-retry).
        $failedResponse->cancel();

        return $this->httpClient->request('POST', $this->baseUrl.$this->path, $retryOptions);
    }

    /**
     * Privacy-safe outgoing request summary: structural metadata only (no prompts/tokens/account IDs).
     *
     * input_types summarizes item type/role keys seen in the input array without logging content.
     *
     * @param array<string, mixed> $jsonBody
     */
    private function logRequestSummary(Model $model, array $jsonBody): void
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

        $this->logger->info('llm.provider.request_prepared', [
            'event_type' => 'llm.provider.request_prepared',
            'transport' => 'sse',
            'request_url_path' => $this->path,
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
            'originator' => $this->originator,
        ]);
    }
}
