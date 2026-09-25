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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testEachWorkerDiscardsItsOwnContinuationAfterSharedGenerationChanges(): void
    {
        $frames = [[], []];
        $clients = [];
        foreach ([0, 1] as $worker) {
            $connection = $this->createStreamingConnection($frames[$worker]);
            $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
            $connector->method('connect')->willReturn($connection);
            $clients[] = new CodexWebSocketModelClient(
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
        }

        $options = ['prompt_cache_key' => '0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee'];
        foreach ($clients as $client) {
            iterator_to_array($client->request(new CodexModel('gpt-5.6-luna'), [
                'input' => [['role' => 'user', 'content' => 'before compaction']],
            ], $options + [CodexRequestBodyFactory::CONTINUATION_GENERATION => 1])->getDataStream());
        }

        foreach ($clients as $client) {
            iterator_to_array($client->request(new CodexModel('gpt-5.6-luna'), [
                'input' => [['role' => 'user', 'content' => 'new summary']],
            ], $options + [CodexRequestBodyFactory::CONTINUATION_GENERATION => 2])->getDataStream());
        }

        foreach ($frames as $workerFrames) {
            $this->assertCount(2, $workerFrames);
            $frame = json_decode($workerFrames[1], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('previous_response_id', $frame);
            $this->assertArrayNotHasKey(CodexRequestBodyFactory::CONTINUATION_GENERATION, $frame);
            $this->assertSame([['role' => 'user', 'content' => 'new summary']], $frame['input']);
        }
    }

    public function testChangedToolsStartFreshChain(): void
    {
        $frames = [];
        $connection = $this->createStreamingConnection($frames);
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturn($connection);
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

        $options = ['prompt_cache_key' => '0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee'];
        iterator_to_array($client->request(new CodexModel('gpt-5.6-luna'), [
            'input' => [['role' => 'user', 'content' => 'first']],
            'tools' => [['type' => 'function', 'name' => 'read', 'description' => 'Read files', 'parameters' => ['type' => 'object']]],
        ], $options)->getDataStream());

        iterator_to_array($client->request(new CodexModel('gpt-5.6-luna'), [
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second'],
            ],
            'tools' => [['type' => 'function', 'name' => 'new_mcp_tool', 'description' => 'New catalog tool', 'parameters' => ['type' => 'object']]],
        ], $options)->getDataStream());

        $this->assertCount(2, $frames);
        $frame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('previous_response_id', $frame);
        $this->assertCount(3, $frame['input']);
        $this->assertSame('new_mcp_tool', $frame['tools'][0]['name']);
    }

    /**
     * A failed continuation must not be retried implicitly. The next distinct
     * request owns a fresh socket and sends its full history, not the old delta.
     */
    #[DataProvider('interruptedContinuationProvider')]
    public function testInterruptedContinuationClosesSocketAndNextRequestSendsFullHistory(string $interruption): void
    {
        $frames = [];
        $freshFrames = [];
        $oldConnection = $this->createMock(WebsocketConnection::class);
        $oldConnection->expects($this->once())->method('close');
        $oldConnection->method('sendText')->willReturnCallback(static function (string $frame) use (&$frames): void {
            $frames[] = $frame;
        });
        $oldConnection->method('receive')->willReturnCallback(static function () use (&$frames, $interruption): ?WebsocketMessage {
            if (1 === \count($frames)) {
                return WebsocketMessage::fromText(json_encode([
                    'type' => 'response.completed',
                    'response' => [
                        'id' => 'resp_before_interruption',
                        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
                    ],
                ], \JSON_THROW_ON_ERROR));
            }

            return 'disconnect' === $interruption ? null : WebsocketMessage::fromText(json_encode([
                'type' => 'error',
                'error' => ['code' => 'previous_response_not_found', 'message' => 'Continuation is unavailable.'],
            ], \JSON_THROW_ON_ERROR));
        });
        /** @var WebsocketConnection&\PHPUnit\Framework\MockObject\MockObject $freshConnection */
        $freshConnection = $this->createStreamingConnection($freshFrames);
        $freshConnection->expects($this->once())->method('close');
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->exactly(2))->method('connect')->willReturn($oldConnection, $freshConnection);
        $cache = new CodexWebSocketConnectionCache();
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
        );
        $model = new CodexModel('gpt-6-astra');
        $options = ['prompt_cache_key' => '0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee'];
        $input = [['role' => 'user', 'content' => 'first']];

        try {
            $first = $client->request($model, ['input' => $input], $options);
            iterator_to_array($first->getDataStream());
            $input[] = ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'];
            $input[] = ['role' => 'user', 'content' => 'second'];
            $interrupted = $client->request($model, ['input' => $input], $options);
            $this->assertInstanceOf(RawWebSocketResult::class, $interrupted);
            $delta = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame('resp_before_interruption', $delta['previous_response_id']);
            $this->assertSame([$input[2]], $delta['input']);

            if ('cancel' === $interruption) {
                $interrupted->abort();
            } else {
                try {
                    iterator_to_array($interrupted->getDataStream());
                    $this->fail('Expected the interrupted continuation to fail.');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString(
                        'disconnect' === $interruption ? 'closed before response.completed' : 'previous_response_not_found',
                        $e->getMessage(),
                    );
                }
            }
            $this->assertCount(2, $frames);
            $this->assertSame([], $freshFrames, 'A failed request must not be resent.');

            $input[] = ['role' => 'user', 'content' => 'next distinct request'];
            $next = $client->request($model, ['input' => $input], $options);
            iterator_to_array($next->getDataStream());
            $this->assertCount(1, $freshFrames);
            $fresh = json_decode($freshFrames[0], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('previous_response_id', $fresh);
            $this->assertSame($input, $fresh['input']);
        } finally {
            $cache->closeAll();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function interruptedContinuationProvider(): iterable
    {
        yield 'cancel before consuming response' => ['cancel'];
        yield 'peer disconnects during continuation' => ['disconnect'];
        yield 'provider rejects continuation' => ['rejection'];
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

        $logger = new TestLogger();
        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            logger: $logger,
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

        $baselineLogs = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'codex.websocket.continuation.baseline' === $record['message'],
        ));
        $this->assertCount(2, $baselineLogs);
        $this->assertSame('streamed', $baselineLogs[0]['context']['baseline_source']);
        $this->assertSame('unavailable', $baselineLogs[0]['context']['source_comparison']);
        $this->assertSame(1, $baselineLogs[0]['context']['streamed_done_count']);
        $this->assertSame(0, $baselineLogs[0]['context']['terminal_output_count']);
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
        /** @var list<string> $frames */
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
        $this->assertGreaterThanOrEqual(2, \count($frames));

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
            $frame = json_decode($this->frameAt($frames, $index + 2), true, flags: \JSON_THROW_ON_ERROR);
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
        $resumed = json_decode($this->frameAt($frames, 4), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('previous_response_id', $resumed);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::REASONING_RESET, $resumed);
        $this->assertSame($secondPayload['input'], $resumed['input']);
        $this->assertSame('medium', $resumed['reasoning']['effort']);
        $this->assertSame(5, \count($frames));
    }

    public function testUnexpectedContinuationMismatchFailsLoudlyWithoutSendingFrame(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-111111111111';
        self::assertUuidVersion7($cacheKey);

        $frames = [];
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())->method('close');
        $connection->method('sendText')->willReturnCallback(static function (string $frame) use (&$frames): void {
            $frames[] = $frame;
        });
        $connection->method('receive')->willReturnCallback(static function (): WebsocketMessage {
            return WebsocketMessage::fromText(json_encode([
                'type' => 'response.completed',
                'response' => [
                    'id' => 'resp_mismatch_1',
                    'output' => [[
                        'type' => 'function_call',
                        'id' => 'fc_native',
                        'call_id' => 'call_native',
                        'name' => 'read',
                        'arguments' => '{"path":"./probe.txt"}',
                    ]],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $connectCount = 0;
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });

        $logger = new TestLogger();
        $cache = new CodexWebSocketConnectionCache();
        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
            logger: $logger,
            transport: CodexTransportEnum::WebsocketCached,
            connectionCache: $cache,
        );

        $options = ['prompt_cache_key' => $cacheKey];
        $first = $client->request(new CodexModel('gpt-5.6-luna'), [
            'input' => [['role' => 'user', 'content' => 'read fixture']],
        ], $options);
        iterator_to_array($first->getDataStream());
        $this->assertCount(1, $frames);

        $exception = null;
        try {
            $client->request(new CodexModel('gpt-5.6-luna'), [
                'input' => [
                    ['role' => 'user', 'content' => 'read fixture'],
                    [
                        'type' => 'function_call',
                        'id' => 'fc_native',
                        'call_id' => 'call_native',
                        'name' => 'read',
                        'arguments' => '{"path":"./different.txt"}',
                    ],
                    [
                        'type' => 'function_call_output',
                        'call_id' => 'call_native',
                        'output' => 'fixture',
                    ],
                ],
            ], $options);
            $this->fail('Expected CodexWebSocketContinuationMismatchException.');
        } catch (\Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationMismatchException $caught) {
            $exception = $caught;
            $this->assertStringContainsString('prefix_mismatch', $exception->getMessage());
            $this->assertStringNotContainsString('./different.txt', $exception->getMessage());
            $this->assertStringNotContainsString($cacheKey, $exception->getMessage());
        }

        $this->assertCount(1, $frames, 'Mismatch must not send a second wire frame.');
        $this->assertSame(1, $connectCount);
        $mismatchLogs = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'codex.websocket.continuation.mismatch' === $record['message'],
        ));
        $this->assertCount(1, $mismatchLogs);
        $this->assertSame('prefix_mismatch', $mismatchLogs[0]['context']['reason']);
        $this->assertFalse($mismatchLogs[0]['context']['prefix_normalized_equal']);
    }

    public function testContinuationResetStartsFreshBaselineThenAllowsDelta(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-222222222222';
        self::assertUuidVersion7($cacheKey);

        $connectCount = 0;
        $frames = [];
        $connection = $this->createStreamingConnection($frames);
        /** @var WebsocketConnection&\PHPUnit\Framework\MockObject\MockObject $summaryConnection */
        $summaryConnection = $this->createStreamingConnection($frames);
        $summaryConnection->expects($this->once())->method('close');
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $connection, $summaryConnection): WebsocketConnection {
            ++$connectCount;

            return 2 === $connectCount ? $summaryConnection : $connection;
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
        $first = $client->request(new CodexModel('gpt-5.6-luna'), [
            'input' => [['role' => 'user', 'content' => 'pre-compact']],
        ], $options);
        iterator_to_array($first->getDataStream());

        $summarize = $client->request(new CodexModel('gpt-5.6-luna'), [
            'input' => [
                ['role' => 'user', 'content' => 'pre-compact'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'summarize for compaction'],
            ],
        ], $options + [CodexRequestBodyFactory::CONTINUATION_RESET => true]);
        iterator_to_array($summarize->getDataStream());

        $postCompact = $client->request(new CodexModel('gpt-5.6-luna'), [
            'input' => [
                ['role' => 'user', 'content' => 'compact summary'],
                ['role' => 'user', 'content' => 'continue after compact'],
            ],
        ], $options + [CodexRequestBodyFactory::CONTINUATION_GENERATION => 1]);
        iterator_to_array($postCompact->getDataStream());

        $followUp = $client->request(new CodexModel('gpt-5.6-luna'), [
            'input' => [
                ['role' => 'user', 'content' => 'compact summary'],
                ['role' => 'user', 'content' => 'continue after compact'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'next'],
            ],
        ], $options + [CodexRequestBodyFactory::CONTINUATION_GENERATION => 1]);
        iterator_to_array($followUp->getDataStream());

        $this->assertSame(2, $connectCount);
        $this->assertCount(4, $frames);

        $summarizeFrame = json_decode($frames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('previous_response_id', $summarizeFrame);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::CONTINUATION_RESET, $summarizeFrame);
        $this->assertCount(3, $summarizeFrame['input']);

        $postCompactFrame = json_decode($frames[2], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('previous_response_id', $postCompactFrame);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::CONTINUATION_GENERATION, $postCompactFrame);
        $this->assertCount(2, $postCompactFrame['input']);

        $followUpFrame = json_decode($frames[3], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('resp_cached_1', $followUpFrame['previous_response_id']);
        $this->assertSame([['role' => 'user', 'content' => 'next']], $followUpFrame['input']);
    }

    public function testRejectedSummarizationDoesNotReplaceOriginalChatContinuation(): void
    {
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-333333333333';
        self::assertUuidVersion7($cacheKey);

        $chatFrames = [];
        $summaryFrames = [];
        $chatConnection = $this->createStreamingConnection($chatFrames);
        /** @var WebsocketConnection&\PHPUnit\Framework\MockObject\MockObject $summaryConnection */
        $summaryConnection = $this->createStreamingConnection($summaryFrames);
        $summaryConnection->expects($this->once())->method('close');
        $connectCount = 0;
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturnCallback(static function () use (&$connectCount, $chatConnection, $summaryConnection): WebsocketConnection {
            ++$connectCount;

            return 2 === $connectCount ? $summaryConnection : $chatConnection;
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
        $model = new CodexModel('gpt-5.6-luna');
        $options = ['prompt_cache_key' => $cacheKey];
        iterator_to_array($client->request($model, [
            'input' => [['role' => 'user', 'content' => 'original chat']],
        ], $options)->getDataStream());

        // The provider completes the summary, but the application rejects it
        // and keeps the original chat messages and generation unchanged.
        iterator_to_array($client->request($model, [
            'input' => [['role' => 'user', 'content' => 'summarize original chat']],
        ], $options + [CodexRequestBodyFactory::CONTINUATION_RESET => true])->getDataStream());
        iterator_to_array($client->request($model, [
            'input' => [
                ['role' => 'user', 'content' => 'original chat'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'continue original chat'],
            ],
        ], $options)->getDataStream());

        $this->assertSame(2, $connectCount);
        $this->assertCount(1, $summaryFrames);
        $this->assertArrayNotHasKey('previous_response_id', json_decode($summaryFrames[0], true, flags: \JSON_THROW_ON_ERROR));
        $this->assertCount(2, $chatFrames);
        $next = json_decode($chatFrames[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('resp_cached_1', $next['previous_response_id']);
        $this->assertSame([['role' => 'user', 'content' => 'continue original chat']], $next['input']);
    }

    /**
     * @param list<string> $frames
     */
    private function createStreamingConnection(array &$frames): WebsocketConnection&\PHPUnit\Framework\MockObject\MockObject
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
     * @param list<string> $frames
     */
    private function frameAt(array $frames, int $index): string
    {
        $this->assertArrayHasKey($index, $frames);

        return $frames[$index];
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
