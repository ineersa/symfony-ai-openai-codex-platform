<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Cancellation;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Socket;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455Connection;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Rfc6455Client;
use Amp\Websocket\WebsocketMessage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheSettings;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectorInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketHandshakeHeadersFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketModelClient;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketUrlResolver;
use Symfony\AI\Platform\Bridge\OpenAICodex\RawWebSocketResult;
use Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support\TestLogger;
use Symfony\Component\Clock\MockClock;

use function Amp\async;

#[AllowMockObjectsWithoutExpectations]
final class CodexWebSocketCachedModelClientTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testSecondCompatibleRequestReusesConnectionAndSendsDelta(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee';
        self::assertUuidVersion7($cacheKey);

        $connectCount = 0;
        $frames = [];
        $connection = $this->createStreamingConnection($frames);

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: new CodexWebSocketConnectionCache(),
        );

        $options = ['prompt_cache_key' => $cacheKey];
        $firstPayload = ['input' => [['role' => 'user', 'content' => 'first']]];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), $firstPayload, $options);
        $this->assertInstanceOf(RawWebSocketResult::class, $first);
        iterator_to_array($first->getDataStream());

        $secondPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second'],
            ],
        ];
        $second = $client->request(new CodexModel('gpt-5.6-luna'), $secondPayload, $options);
        iterator_to_array($second->getDataStream());

        $this->assertSame(1, $connectCount);
        $this->assertCount(2, $frames);
        $secondFrame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('resp_cached_1', $secondFrame['previous_response_id']);
        $this->assertCount(1, $secondFrame['input']);
        $this->assertSame('second', $secondFrame['input'][0]['content']);
        // Delta frames must keep the resolved prompt_cache_key with previous_response_id.
        $this->assertSame($cacheKey, $secondFrame['prompt_cache_key'] ?? null);
    }

    /**
     * Session-33 regression: when a tool-call turn streams function_call via
     * response.output_item.done but the terminal response.output is empty, the
     * cached continuation baseline must still own that function_call so the
     * next previous_response_id request sends only the matching function_call_output.
     */
    public function testToolContinuationDeltaOmitsReplayOfStreamedFunctionCallWhenTerminalOutputEmpty(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-ffffffffffff';
        self::assertUuidVersion7($cacheKey);

        $functionCall = [
            'type' => 'function_call',
            'id' => 'fc_streamed_1',
            'call_id' => 'fc_streamed_1',
            'name' => 'symfony-ai-openai-codex_docs',
            'arguments' => '{"path":"docs/settings.md"}',
        ];
        $functionCallOutput = [
            'type' => 'function_call_output',
            'call_id' => 'fc_streamed_1',
            'output' => '{"ok":true}',
        ];

        $inboundQueues = [
            [
                [
                    'type' => 'response.output_item.done',
                    'item' => $functionCall,
                ],
                [
                    'type' => 'response.completed',
                    'response' => [
                        'id' => 'resp_tool_1',
                        // Empty/absent terminal output is the failure mode from session 33.
                        'output' => [],
                    ],
                ],
            ],
            [
                [
                    'type' => 'response.completed',
                    'response' => [
                        'id' => 'resp_tool_2',
                        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => 'done']],
                    ],
                ],
            ],
        ];

        $connectCount = 0;
        $frames = [];
        $connection = $this->createQueuedStreamingConnection($frames, $inboundQueues);

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: new CodexWebSocketConnectionCache(),
        );

        $options = ['prompt_cache_key' => $cacheKey];
        $firstPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'read docs'],
            ],
        ];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), $firstPayload, $options);
        iterator_to_array($first->getDataStream());

        $secondPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'read docs'],
                $functionCall,
                $functionCallOutput,
            ],
        ];
        $second = $client->request(new CodexModel('gpt-5.6-luna'), $secondPayload, $options);
        iterator_to_array($second->getDataStream());

        $this->assertSame(1, $connectCount);
        $this->assertCount(2, $frames);

        $secondFrame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('resp_tool_1', $secondFrame['previous_response_id'] ?? null);
        // Explicitly prove the prior function_call was not replayed in the delta.
        $this->assertSame([$functionCallOutput], $secondFrame['input']);
    }

    /**
     * Session-33 hang regression: a retained cached Amp WebSocket whose peer stops
     * reading must not pin the worker in sendText() forever. Bound send, invalidate
     * the cache entry, and never auto-resend that ambiguous attempt. A later distinct
     * request must open a fresh connection and send full context (no previous_response_id).
     *
     * Uses a real Amp Rfc6455Client over a socket pair so the hang is in WritableResourceStream,
     * not a mock that simply throws/sleeps.
     */
    public function testCachedSendTimeoutOnBackpressuredPeerInvalidatesWithoutDuplicateSend(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-aaaaaaaaaaaa';
        self::assertUuidVersion7($cacheKey);

        $connectCount = 0;
        $connections = [];
        $peerSockets = [];
        $peerMessages = [];
        $peerKeepers = [];
        $peerLogger = new TestLogger();

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(function () use (
            &$connectCount,
            &$connections,
            &$peerSockets,
            &$peerMessages,
            &$peerKeepers,
            $peerLogger,
        ): WebsocketConnection {
            ++$connectCount;
            [$clientSocket, $peerSocket] = Socket\createSocketPair();
            $peerSockets[] = $peerSocket;

            // First peer: complete one response, then stop reading so the next
            // outbound write blocks in Amp's real WritableResourceStream.
            // Later peers: complete immediately so recovery path can finish.
            $index = $connectCount - 1;
            $peerKeepers[] = async(function () use ($peerSocket, $index, &$peerMessages, $peerLogger): void {
                $cancellation = new TimeoutCancellation(5.0);
                try {
                    $message = $this->readClientWebSocketMessage($peerSocket, $cancellation);
                    $peerMessages[$index][] = $message;
                    if (0 === $index) {
                        $peerSocket->write($this->encodeServerWebSocketText(json_encode([
                            'type' => 'response.completed',
                            'response' => [
                                'id' => 'resp_cached_peer_1',
                                'output' => [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
                            ],
                        ], \JSON_THROW_ON_ERROR)));
                        // The owning test retains the socket without reading further.

                        return;
                    }

                    $peerSocket->write($this->encodeServerWebSocketText(json_encode([
                        'type' => 'response.completed',
                        'response' => [
                            'id' => 'resp_fresh_peer_2',
                            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => 'recovered']],
                        ],
                    ], \JSON_THROW_ON_ERROR)));
                } catch (\Throwable $exception) {
                    // Teardown can interrupt peer I/O after an assertion failure.
                    $peerLogger->debug('test.peer_io_stopped', ['exception_class' => $exception::class]);
                }
            });

            $client = new Rfc6455Client($clientSocket, true, closePeriod: 0.05);
            $request = new Request('ws://127.0.0.1/codex/responses');
            $response = new Response('1.1', 101, null, [], null, $request);
            $connection = new Rfc6455Connection($client, $response);
            $connections[] = $connection;

            return $connection;
        });

        $logger = new TestLogger();
        $cache = new CodexWebSocketConnectionCache(logger: $logger);
        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            logger: $logger,
            // Short idle timeout so the regression stays deterministic and fast.
            idleTimeoutSeconds: 0.35,
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: $cache,
        );

        try {
            $options = ['prompt_cache_key' => $cacheKey];
            $first = $client->request(
                new CodexModel('gpt-5.6-luna'),
                ['input' => [['role' => 'user', 'content' => 'first']]],
                $options,
            );
            iterator_to_array($first->getDataStream());
            $this->assertSame(1, $connectCount);
            $this->assertTrue(false === $connections[0]->isClosed());

            // Divergent input forces full-context fallback on the retained socket.
            // Pre-fix, sendText() could hang forever here under peer backpressure.
            $started = microtime(true);
            try {
                $client->request(
                    new CodexModel('gpt-5.6-luna'),
                    [
                        'input' => [
                            ['role' => 'user', 'content' => 'first'],
                            ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                            // Large payload ensures the write exceeds socket buffer when peer stops reading.
                            ['role' => 'user', 'content' => str_repeat('x', 256 * 1024)],
                        ],
                    ],
                    $options,
                );
                $this->fail('Expected send timeout on backpressured cached socket');
            } catch (\RuntimeException $e) {
                $this->assertSame('Codex WebSocket send timeout.', $e->getMessage());
            }
            $elapsed = microtime(true) - $started;
            $this->assertLessThan(2.5, $elapsed, 'send timeout must bound the hang and close promptly');
            // @phpstan-ignore method.alreadyNarrowedType (The connector callback can change the counter during request.)
            $this->assertSame(1, $connectCount, 'timed-out attempt must not auto-reconnect/resend');
            $this->assertTrue($connections[0]->isClosed());

            $entries = (new \ReflectionClass($cache))->getProperty('entries')->getValue($cache);
            $this->assertSame([], $entries, 'cache entry must be invalidated after send timeout');

            $timeoutLogs = array_values(array_filter(
                $logger->records,
                static fn (array $record): bool => 'codex.websocket.io_timeout' === $record['message'],
            ));
            $this->assertNotEmpty($timeoutLogs);
            $this->assertSame('send', $timeoutLogs[0]['context']['phase']);
            $this->assertSame('unknown', $timeoutLogs[0]['context']['delivery_status']);
            $this->assertTrue($timeoutLogs[0]['context']['cache_reused']);

            // A later distinct request must acquire a fresh connection and use full context.
            $third = $client->request(
                new CodexModel('gpt-5.6-luna'),
                [
                    'input' => [
                        ['role' => 'user', 'content' => 'first'],
                        ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                        ['role' => 'user', 'content' => 'after-timeout'],
                    ],
                ],
                $options,
            );
            iterator_to_array($third->getDataStream());

            // @phpstan-ignore method.impossibleType (The connector callback changes the counter during request.)
            $this->assertSame(2, $connectCount);
            $this->assertArrayHasKey(1, $peerMessages);
            $this->assertCount(1, $peerMessages[1], 'fresh connection should receive exactly one full-context request');
            $frame = json_decode($peerMessages[1][0], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('previous_response_id', $frame);
            $this->assertSame('after-timeout', $frame['input'][2]['content']);
        } finally {
            foreach ($peerSockets as $peerSocket) {
                $peerSocket->close();
            }
            $cache->closeAll();
            foreach ($peerKeepers as $keeper) {
                $keeper->await();
            }
        }
    }

    public function testPostIdleExpirySendsFullContextWithoutPreviousResponseId(): void
    {
        $cacheKey = '0194ffff-bbbb-7ccc-8ddd-444444444444';
        self::assertUuidVersion7($cacheKey);

        $clock = new MockClock(new \DateTimeImmutable('2026-07-13 20:44:00'));
        $cache = new CodexWebSocketConnectionCache(clock: $clock);
        $settings = new CodexWebSocketCacheSettings(idleTtlSeconds: 300, maxAgeSeconds: 3300);

        $connectCount = 0;
        $frames = [];
        $connection = $this->createStreamingConnection($frames);

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: $cache,
            cacheSettings: $settings,
        );

        $options = ['prompt_cache_key' => $cacheKey];
        $firstPayload = ['input' => [['role' => 'user', 'content' => 'first']]];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), $firstPayload, $options);
        iterator_to_array($first->getDataStream());

        $clock->sleep(308);

        $secondPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'after-idle'],
            ],
        ];
        $second = $client->request(new CodexModel('gpt-5.6-luna'), $secondPayload, $options);
        iterator_to_array($second->getDataStream());

        $this->assertSame(2, $connectCount);
        $this->assertCount(2, $frames);
        $secondFrame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('previous_response_id', $secondFrame);
        $this->assertCount(3, $secondFrame['input']);
        $this->assertSame('after-idle', $secondFrame['input'][2]['content']);
    }

    public function testPlainWebsocketStillOpensConnectionPerRequest(): void
    {
        $connectCount = 0;
        $frames = [];
        $connection = $this->createStreamingConnection($frames);
        $connection->expects($this->exactly(2))->method('close');

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::Websocket,
        );

        foreach ([1, 2] as $_) {
            $result = $client->request(new CodexModel('gpt-5.6-luna'), ['input' => [['role' => 'user', 'content' => 'hi']]]);
            iterator_to_array($result->getDataStream());
        }

        $this->assertSame(2, $connectCount);
    }

    public function testBusyOneShotSendFailureClosesConnectionOnce(): void
    {
        $cacheKey = '0194aaaa-bbbb-7ccc-8ddd-aaaaaaaaaaaa';
        self::assertUuidVersion7($cacheKey);

        $frames = [];
        $primary = $this->createStreamingConnection($frames);
        $oneShot = $this->createMock(WebsocketConnection::class);
        $oneShot->expects($this->once())->method('close');
        $oneShot->expects($this->once())->method('sendText')->willThrowException(new \RuntimeException('send failed'));

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnOnConsecutiveCalls($primary, $oneShot);

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: new CodexWebSocketConnectionCache(),
        );

        $options = ['prompt_cache_key' => $cacheKey];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), ['input' => [['role' => 'user', 'content' => 'first']]], $options);

        try {
            $client->request(new CodexModel('gpt-5.6-luna'), ['input' => [['role' => 'user', 'content' => 'second']]], $options);
            $this->fail('Expected send failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Codex WebSocket request frame could not be sent.', $e->getMessage());
        }

        iterator_to_array($first->getDataStream());
    }

    public function testGeneratedCorrelationBypassesCacheOn401AndAlignsPromptCacheKey(): void
    {
        $connectCalls = [];
        $sentFrame = '';
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('sendText')->willReturnCallback(static function (string $data) use (&$sentFrame): void {
            $sentFrame = $data;
        });
        $connection->method('receive')->willReturnCallback(static function (): WebsocketMessage {
            return WebsocketMessage::fromText(json_encode(['type' => 'response.completed', 'response' => ['id' => 'r1', 'output' => []]], \JSON_THROW_ON_ERROR));
        });

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(function (string $url, array $headers, float $timeout) use (&$connectCalls, $connection) {
            $connectCalls[] = $headers;
            if (1 === \count($connectCalls)) {
                throw $this->websocketConnectException(401);
            }

            return $connection;
        });

        $cache = new CodexWebSocketConnectionCache();
        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'stale',
            'acct-1',
            accessTokenRefresher: static fn (): string => 'fresh',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: $cache,
        );

        $result = $client->request(new CodexModel('gpt-5.6-luna'), ['input' => [['role' => 'user', 'content' => 'hi']]]);
        iterator_to_array($result->getDataStream());

        $this->assertCount(2, $connectCalls);
        $this->assertNotSame($connectCalls[0]['session-id'], $connectCalls[1]['session-id']);
        $frame = json_decode($sentFrame, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($connectCalls[1]['session-id'], $frame['prompt_cache_key']);

        $reflection = new \ReflectionClass($cache);
        $prop = $reflection->getProperty('entries');
        $this->assertSame([], $prop->getValue($cache));
    }

    public function testAstraReasoningSelectionChangeKeepsBaselineAndPrependsConfigurationUpdateOnDelta(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-aaaaaaaaaaaa';
        self::assertUuidVersion7($cacheKey);

        $connectCount = 0;
        $frames = [];
        $connection = $this->createStreamingConnection($frames);

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: new CodexWebSocketConnectionCache(),
        );

        $options = [
            'prompt_cache_key' => $cacheKey,
            'reasoning' => ['effort' => 'medium', 'summary' => 'auto'],
        ];
        $firstPayload = ['input' => [['role' => 'user', 'content' => 'first']]];
        $first = $client->request(new CodexModel('gpt-6-astra'), $firstPayload, $options);
        $this->assertInstanceOf(RawWebSocketResult::class, $first);
        iterator_to_array($first->getDataStream());

        $secondOptions = [
            'prompt_cache_key' => $cacheKey,
            'reasoning' => ['effort' => 'medium', 'summary' => 'auto'],
        ];
        $secondPayload = [
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'second'],
            ],
        ];
        $second = $client->request(new CodexModel('gpt-6-astra'), $secondPayload, $secondOptions);
        iterator_to_array($second->getDataStream());

        $this->assertSame(1, $connectCount);
        $this->assertCount(2, $frames);

        $firstFrame = json_decode($frames[0], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('medium', $firstFrame['reasoning']['effort']);
        $this->assertSame([['role' => 'user', 'content' => 'first']], $firstFrame['input']);

        $secondFrame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('resp_cached_1', $secondFrame['previous_response_id']);
        $this->assertSame('medium', $secondFrame['reasoning']['effort']);
        $this->assertSame([
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
            ['role' => 'user', 'content' => 'second'],
        ], $secondFrame['input']);

        foreach (['high', 'low'] as $index => $effort) {
            $secondPayload['input'][] = ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'];
            $secondPayload['input'][] = ['type' => 'configuration_update', 'reasoning' => ['effort' => $effort]];
            $secondPayload['input'][] = ['role' => 'user', 'content' => 'next-'.$index];
            $result = $client->request(new CodexModel('gpt-6-astra'), $secondPayload, $secondOptions);
            iterator_to_array($result->getDataStream());
            /** @var list<string> $frames Updated by the send callback. */
            $frame = json_decode($frames[$index + 2], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame('resp_cached_1', $frame['previous_response_id']);
            $this->assertSame('medium', $frame['reasoning']['effort']);
            $this->assertSame([
                ['type' => 'configuration_update', 'reasoning' => ['effort' => $effort]],
                ['role' => 'user', 'content' => 'next-'.$index],
            ], $frame['input']);
        }

        $secondPayload['input'][] = ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'];
        $secondPayload['input'][] = ['role' => 'user', 'content' => 'resumed'];
        $result = $client->request(new CodexModel('gpt-6-astra'), $secondPayload, $options + [CodexRequestBodyFactory::REASONING_RESET => true]);
        iterator_to_array($result->getDataStream());
        /** @var list<string> $frames Updated by the send callback. */
        $resumed = json_decode($frames[4], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('previous_response_id', $resumed);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::REASONING_RESET, $resumed);
        $this->assertSame($secondPayload['input'], $resumed['input']);
        $this->assertSame('medium', $resumed['reasoning']['effort']);
    }

    /**
     * @param list<string> $frames
     *
     * @return WebsocketConnection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createStreamingConnection(array &$frames): WebsocketConnection
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('sendText')->willReturnCallback(static function (string $frame) use (&$frames): void {
            $frames[] = $frame;
        });
        $connection->method('receive')->willReturnCallback(static function (): WebsocketMessage {
            return WebsocketMessage::fromText(json_encode([
                'type' => 'response.completed',
                'response' => [
                    'id' => 'resp_cached_1',
                    'output' => [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        return $connection;
    }

    /**
     * @param list<string>                     $frames
     * @param list<list<array<string, mixed>>> $inboundQueues per request stream
     */
    private function createQueuedStreamingConnection(array &$frames, array $inboundQueues): WebsocketConnection
    {
        $requestIndex = -1;
        $eventIndex = 0;

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('sendText')->willReturnCallback(static function (string $frame) use (&$frames, &$requestIndex, &$eventIndex): void {
            $frames[] = $frame;
            ++$requestIndex;
            $eventIndex = 0;
        });
        $connection->method('receive')->willReturnCallback(static function () use (&$requestIndex, &$eventIndex, $inboundQueues): WebsocketMessage {
            if ($requestIndex < 0 || !isset($inboundQueues[$requestIndex])) {
                self::fail('receive() without a matching sendText() request stream');
            }

            $queue = $inboundQueues[$requestIndex];
            if (!isset($queue[$eventIndex])) {
                self::fail('receive() exhausted inbound queue for request '.$requestIndex);
            }

            $event = $queue[$eventIndex];
            ++$eventIndex;

            return WebsocketMessage::fromText(json_encode($event, \JSON_THROW_ON_ERROR));
        });

        return $connection;
    }

    /**
     * @return non-empty-string
     */
    private function readClientWebSocketMessage(Socket\Socket $socket, Cancellation $cancellation): string
    {
        $payload = '';
        while (true) {
            $b1 = \ord($this->readSocketBytes($socket, 1, $cancellation)[0]);
            $b2 = \ord($this->readSocketBytes($socket, 1, $cancellation)[0]);
            $fin = (0x80 & $b1) !== 0;
            $length = 0x7F & $b2;
            $masked = (0x80 & $b2) !== 0;
            if (126 === $length) {
                $length = unpack('n', $this->readSocketBytes($socket, 2, $cancellation))[1];
            } elseif (127 === $length) {
                $length = unpack('J', $this->readSocketBytes($socket, 8, $cancellation))[1];
            }
            $mask = $masked ? $this->readSocketBytes($socket, 4, $cancellation) : '';
            $data = $this->readSocketBytes($socket, $length, $cancellation);
            if ($masked) {
                $unmasked = '';
                for ($i = 0; $i < $length; ++$i) {
                    $unmasked .= $data[$i] ^ $mask[$i % 4];
                }
                $data = $unmasked;
            }
            $payload .= $data;
            if ($fin) {
                if ('' === $payload) {
                    throw new \RuntimeException('Empty websocket payload from peer.');
                }

                return $payload;
            }
        }
    }

    private function readSocketBytes(Socket\Socket $socket, int $length, Cancellation $cancellation): string
    {
        $buffer = '';
        while (\strlen($buffer) < $length) {
            $chunk = $socket->read($cancellation, $length - \strlen($buffer));
            if (null === $chunk) {
                throw new \RuntimeException('Peer socket closed while reading websocket frame.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function encodeServerWebSocketText(string $payload): string
    {
        $length = \strlen($payload);
        if ($length < 126) {
            return \chr(0x81).\chr($length).$payload;
        }
        if ($length < 65536) {
            return \chr(0x81).\chr(126).pack('n', $length).$payload;
        }

        return \chr(0x81).\chr(127).pack('J', $length).$payload;
    }

    private function websocketConnectException(int $status): WebsocketConnectException
    {
        $request = new Request('wss://chatgpt.com/backend-api/codex/responses');
        $response = new Response('1.1', $status, null, [], null, $request);

        return new WebsocketConnectException('upgrade failed', $response);
    }
}
