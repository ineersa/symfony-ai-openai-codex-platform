<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModelClient;
use Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support\TestLogger;
use Symfony\AI\Platform\Model;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface as HttpResponse;

final class CodexModelClientTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testItSupportsCodexModel(): void
    {
        $modelClient = new CodexModelClient(new MockHttpClient(), 'https://chatgpt.com/backend-api', 'test-token', 'acct-123');

        $this->assertTrue($modelClient->supports(new CodexModel('gpt-5.5')));
    }

    public function testItDoesNotSupportOtherModels(): void
    {
        $modelClient = new CodexModelClient(new MockHttpClient(), 'https://chatgpt.com/backend-api', 'test-token', 'acct-123');

        $this->assertFalse($modelClient->supports(new Model('test-model')));
    }

    public function testItIsExecutingTheCorrectRequest(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://chatgpt.com/backend-api/codex/responses', $url);
            self::assertSame('Authorization: Bearer test-access-token', $options['normalized_headers']['authorization'][0]);
            self::assertSame('chatgpt-account-id: acct-123', $options['normalized_headers']['chatgpt-account-id'][0]);
            self::assertSame('originator: symfony-ai-openai-codex', $options['normalized_headers']['originator'][0]);
            self::assertSame('Accept: text/event-stream', $options['normalized_headers']['accept'][0]);
            self::assertSame('User-Agent: symfony-ai-openai-codex', $options['normalized_headers']['user-agent'][0]);
            self::assertSame('OpenAI-Beta: responses=experimental', $options['normalized_headers']['openai-beta'][0]);
            self::assertArrayHasKey('x-client-request-id', $options['normalized_headers']);
            $requestId = $options['normalized_headers']['x-client-request-id'][0];
            self::assertStringStartsWith('x-client-request-id: ', $requestId);
            $requestId = substr($requestId, \strlen('x-client-request-id: '));
            self::assertUuidVersion7($requestId);

            $body = json_decode($options['body'], true);
            self::assertSame($requestId, $body['prompt_cache_key'] ?? null);
            self::assertSame('gpt-5.5', $body['model']);
            self::assertSame('test message', $body['input'][0]['content']);
            self::assertSame(1, $body['temperature']);
            self::assertFalse($body['store']);
            self::assertTrue($body['stream']);
            self::assertSame('low', $body['text']['verbosity']);
            self::assertSame(['reasoning.encrypted_content'], $body['include']);
            self::assertSame('auto', $body['tool_choice']);
            self::assertTrue($body['parallel_tool_calls']);

            return new MockResponse();
        };
        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'test message']]],
            ['temperature' => 1],
        );
    }

    public function testItUsesCustomResponsesPath(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://chatgpt.com/backend-api/custom/responses', $url);

            return new MockResponse();
        };
        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123', '/custom/responses');
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );
    }

    public function testItHandlesStructuredOutputOption(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://chatgpt.com/backend-api/codex/responses', $url);

            $body = json_decode($options['body'], true);
            // Verify structured output fields are preserved
            self::assertSame('json', $body['text']['format']['type']);
            self::assertSame('foo', $body['text']['format']['name']);
            // Verify verbosity is merged alongside format
            self::assertSame('low', $body['text']['verbosity']);

            return new MockResponse();
        };

        $options = [
            'temperature' => 0.7,
            'response_format' => [
                'type' => 'json',
                'json_schema' => [
                    'name' => 'foo',
                    'schema' => [],
                ],
            ],
        ];

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            $options,
        );
    }

    public function testItUsesCustomOriginator(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            self::assertSame('originator: my-app', $options['normalized_headers']['originator'][0]);

            return new MockResponse();
        };
        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123', '/codex/responses', 'my-app');
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );
    }

    public function testPromptCacheKeyDrivesHeadersAndBody(): void
    {
        $providerKey = '0194a000-0000-7000-8000-000000000099';
        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options) use ($providerKey): HttpResponse {
                $header = $options['normalized_headers']['x-client-request-id'][0];
                self::assertSame('x-client-request-id: '.$providerKey, $header);
                self::assertSame('session-id: '.$providerKey, $options['normalized_headers']['session-id'][0]);
                $body = json_decode($options['body'], true);
                self::assertSame($providerKey, $body['prompt_cache_key']);
                self::assertTrue($body['stream']);
                self::assertSame(0.7, $body['temperature']);

                return new MockResponse('', ['http_code' => 200]);
            },
        ]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');
        $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            ['prompt_cache_key' => $providerKey, 'stream' => true, 'temperature' => 0.7],
        );
    }

    public function testItPreservesValidCodexApiKeysInBody(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);
            // Valid Codex API keys must be preserved
            self::assertArrayHasKey('reasoning', $body);
            self::assertSame('high', $body['reasoning']['effort']);
            self::assertArrayHasKey('temperature', $body);
            self::assertSame(0.5, $body['temperature']);
            self::assertArrayHasKey('model', $body);
            self::assertSame('gpt-5.5', $body['model']);
            self::assertArrayHasKey('input', $body);
            // stream is preserved (not stripped) — valid Codex field
            self::assertTrue($body['stream']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $options = [
            'reasoning' => ['effort' => 'high', 'summary' => 'auto'],
            'temperature' => 0.5,
            'stream' => true,
        ];

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            $options,
        );
    }

    public function testItPreservesPayloadAndModelWithProviderFacingOptions(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);
            self::assertTrue($body['stream']);
            self::assertSame('gpt-5.4-mini', $body['model']);
            self::assertSame('Hello world', $body['input'][0]['content']);
            self::assertSame('user', $body['input'][0]['role']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $modelClient->request(
            new CodexModel('gpt-5.4-mini'),
            ['input' => [['role' => 'user', 'content' => 'Hello world']]],
            ['stream' => true],
        );
    }

    public function testItIncludesCodexRequiredDefaultsInBody(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);

            // Codex Responses API required fields
            self::assertFalse($body['store']);
            self::assertTrue($body['stream']);
            self::assertSame('low', $body['text']['verbosity']);
            self::assertSame(['reasoning.encrypted_content'], $body['include']);
            self::assertSame('auto', $body['tool_choice']);
            self::assertTrue($body['parallel_tool_calls']);
            self::assertSame(0.5, $body['temperature']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'test']]],
            ['temperature' => 0.5],
        );
    }

    public function testCodexDefaultsDoNotOverrideExplicitValues(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true);

            // Explicit values must not be overridden by defaults
            self::assertTrue($body['store']);
            self::assertSame('high', $body['text']['verbosity']);
            self::assertSame(['custom_feature'], $body['include']);
            self::assertSame('manual', $body['tool_choice']);
            self::assertFalse($body['parallel_tool_calls']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient($httpClient, 'https://chatgpt.com/backend-api', 'test-access-token', 'acct-123');

        $options = [
            'store' => true,
            'text' => ['verbosity' => 'high'],
            'include' => ['custom_feature'],
            'tool_choice' => 'manual',
            'parallel_tool_calls' => false,
            'stream' => true,
        ];

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'test']]],
            $options,
        );
    }

    public function testLogsRequestSummaryOnRequest(): void
    {
        $loggedContext = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                $this->identicalTo('llm.provider.request_prepared'),
                $this->callback(function (array $context) use (&$loggedContext): bool {
                    $loggedContext = $context;

                    // Must include structural metadata
                    $this->assertArrayHasKey('body_keys', $context);
                    $this->assertArrayHasKey('input_count', $context);
                    $this->assertArrayHasKey('input_types', $context);
                    $this->assertArrayHasKey('model', $context);
                    $this->assertArrayHasKey('has_instructions', $context);
                    $this->assertArrayHasKey('has_stream', $context);
                    $this->assertArrayHasKey('has_include', $context);
                    $this->assertArrayHasKey('has_store', $context);
                    $this->assertArrayHasKey('tool_count', $context);
                    $this->assertArrayHasKey('originator', $context);

                    // Model name is safe
                    $this->assertSame('gpt-5.5', $context['model']);

                    // Must NOT contain sensitive data (keys sampled)
                    $contextStr = implode(' ', (array) $context);
                    $this->assertStringNotContainsString('test-access-token', $contextStr);
                    $this->assertStringNotContainsString('test prompt', $contextStr);
                    $this->assertStringNotContainsString('test-access', $contextStr);

                    return true;
                }),
            );

        $httpClient = new MockHttpClient([static function () {
            return new MockResponse();
        }]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-access-token',
            'acct-123',
            '/codex/responses',
            'symfony-ai-openai-codex',
            $logger,
        );

        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'test prompt']]],
            ['temperature' => 1],
        );

        // Additional structural assertions on the captured context
        $this->assertNotNull($loggedContext);
        $this->assertStringContainsString('input', $loggedContext['body_keys']);
        $this->assertStringContainsString('model', $loggedContext['body_keys']);
        $this->assertSame(1, $loggedContext['input_count']);
        $this->assertStringContainsString('user', $loggedContext['input_types']);
        $this->assertTrue($loggedContext['has_store']);
        $this->assertTrue($loggedContext['has_stream']);
        $this->assertSame('symfony-ai-openai-codex', $loggedContext['originator']);

        // Must contain new diagnostics fields
    }

    /**
     * Without explicit prompt_cache_key, Codex correlation uses a generated UUIDv7 for x-client-request-id and prompt_cache_key.
     */
    public function testItSetsPromptCacheKeyFromGeneratedCorrelationIdWithoutRunId(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $requestId = $options['normalized_headers']['x-client-request-id'][0];
            self::assertStringStartsWith('x-client-request-id: ', $requestId);
            $requestId = substr($requestId, \strlen('x-client-request-id: '));
            self::assertUuidVersion7($requestId);

            $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame($requestId, $body['prompt_cache_key']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );
    }

    public function testExplicitPayloadPromptCacheKeyWinsOverOptions(): void
    {
        $resultCallback = static function (string $method, string $url, array $options): HttpResponse {
            $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('prompt_cache_key', $body);
            self::assertSame('explicit-key', $body['prompt_cache_key']);

            return new MockResponse();
        };

        $httpClient = new MockHttpClient([$resultCallback]);
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );
        $modelClient->request(
            new CodexModel('gpt-5.5'),
            ['input' => [['role' => 'user', 'content' => 'Hello']], 'prompt_cache_key' => 'explicit-key'],
            ['prompt_cache_key' => 'options-key'],
        );
    }

    public function testEmptyPromptCacheKeyUsesGeneratedUuidVersion7AlignedWithHeader(): void
    {
        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options): HttpResponse {
                $header = $options['normalized_headers']['x-client-request-id'][0];
                $requestId = substr($header, \strlen('x-client-request-id: '));
                self::assertUuidVersion7($requestId);

                $body = json_decode($options['body'], true);
                self::assertSame($requestId, $body['prompt_cache_key'] ?? null);

                return new MockResponse('', ['http_code' => 200]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );
        $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']], 'prompt_cache_key' => ''],
        );
    }

    /**
     * Test thesis: a long-lived worker recovers from an expired/revoked token via
     * one force-refresh + retry, without manual auth:codex --refresh.
     */
    public function testRefreshesAndRetriesOnceOn401(): void
    {
        $refreshCalls = 0;
        $requestCount = 0;
        $firstRequestId = null;
        $refresher = static function () use (&$refreshCalls): string {
            ++$refreshCalls;

            return 'new-token';
        };

        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options) use (&$requestCount, &$firstRequestId): HttpResponse {
                ++$requestCount;
                $firstHeader = $options['normalized_headers']['x-client-request-id'][0];
                $firstRequestId = substr($firstHeader, \strlen('x-client-request-id: '));

                return new MockResponse('', ['http_code' => 401]);
            },
            static function (string $method, string $url, array $options) use (&$requestCount, &$firstRequestId): HttpResponse {
                ++$requestCount;
                self::assertSame('Authorization: Bearer new-token', $options['normalized_headers']['authorization'][0]);
                self::assertSame('Accept: text/event-stream', $options['normalized_headers']['accept'][0]);
                self::assertSame('User-Agent: symfony-ai-openai-codex', $options['normalized_headers']['user-agent'][0]);
                self::assertSame('originator: symfony-ai-openai-codex', $options['normalized_headers']['originator'][0]);
                $retryHeader = $options['normalized_headers']['x-client-request-id'][0];
                self::assertStringStartsWith('x-client-request-id: ', $retryHeader);
                $retryRequestId = substr($retryHeader, \strlen('x-client-request-id: '));
                self::assertUuidVersion7($retryRequestId);
                self::assertNotSame($firstRequestId, $retryRequestId);

                $body = json_decode($options['body'], true);
                self::assertSame($retryRequestId, $body['prompt_cache_key'] ?? null);

                return new MockResponse('', ['http_code' => 200]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'symfony-ai-openai-codex',
            null,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(200, $result->getObject()->getStatusCode());
        $this->assertSame(2, $requestCount);
        $this->assertSame(1, $refreshCalls);
    }

    public function test401RetryPreservesExplicitPromptCacheKeyAcrossHeaderAndBody(): void
    {
        $refreshCalls = 0;
        $requestCount = 0;
        $refresher = static function () use (&$refreshCalls): string {
            ++$refreshCalls;

            return 'new-token';
        };

        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options) use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
            static function (string $method, string $url, array $options) use (&$requestCount): HttpResponse {
                ++$requestCount;
                $retryHeader = $options['normalized_headers']['x-client-request-id'][0];
                $retryRequestId = substr($retryHeader, \strlen('x-client-request-id: '));
                self::assertSame('session-run-keep', $retryRequestId);

                $body = json_decode($options['body'], true);
                self::assertSame('session-run-keep', $body['prompt_cache_key'] ?? null);

                return new MockResponse('', ['http_code' => 200]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'symfony-ai-openai-codex',
            null,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
            ['prompt_cache_key' => 'session-run-keep'],
        );

        $this->assertSame(200, $result->getObject()->getStatusCode());
        $this->assertSame(2, $requestCount);
        $this->assertSame(1, $refreshCalls);
    }

    public function test401WhenRefreshReturnsNullDoesNotRetry(): void
    {
        $requestCount = 0;
        $refresher = static function (): ?string {
            return null;
        };

        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'symfony-ai-openai-codex',
            null,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(1, $requestCount);
    }

    public function testPersistent401AfterRetryDoesNotLoop(): void
    {
        $refreshCalls = 0;
        $requestCount = 0;
        $refresher = static function () use (&$refreshCalls): string {
            ++$refreshCalls;

            return 'new-token';
        };

        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'symfony-ai-openai-codex',
            null,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(2, $requestCount);
        $this->assertSame(1, $refreshCalls);
    }

    /**
     * Test thesis: a failed refresh (revoked token) is logged and degrades gracefully
     * to the original 401 — no retry, no silent swallow.
     */
    public function test401WhenRefresherThrowsDoesNotRetryAndLogsRefreshFailed(): void
    {
        $requestCount = 0;
        $refresher = static function (): ?string {
            throw new \RuntimeException('refresh token revoked');
        };

        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $logger = new TestLogger();
        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'stale-token',
            'acct-123',
            '/codex/responses',
            'symfony-ai-openai-codex',
            $logger,
            $refresher,
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(1, $requestCount);

        $refreshFailed = null;
        foreach ($logger->records as $record) {
            if ('codex.token.refresh_failed' === $record['message']) {
                $refreshFailed = $record;
                break;
            }
        }
        $this->assertNotNull($refreshFailed, 'Expected codex.token.refresh_failed warning log');
        $this->assertSame('warning', $refreshFailed['level']);
        $this->assertSame('codex.token.refresh_failed', $refreshFailed['context']['event_type']);
        $this->assertSame('codex_model_client', $refreshFailed['context']['component']);
        $this->assertSame(\RuntimeException::class, $refreshFailed['context']['exception_class']);
    }

    public function test401WithoutRefresherPassesThrough(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient([
            static function () use (&$requestCount): HttpResponse {
                ++$requestCount;

                return new MockResponse('', ['http_code' => 401]);
            },
        ]);

        $modelClient = new CodexModelClient(
            $httpClient,
            'https://chatgpt.com/backend-api',
            'test-token',
            'acct-123',
        );

        $result = $modelClient->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hello']]],
        );

        $this->assertSame(401, $result->getObject()->getStatusCode());
        $this->assertSame(1, $requestCount);
    }
}
