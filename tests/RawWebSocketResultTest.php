<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\ByteStream\ReadableIterableStream;
use Amp\CancelledException;
use Amp\Pipeline\Queue;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketMessage;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCachedStreamContext;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheEntry;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheLease;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheSettings;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCompatibilityFingerprint;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationState;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketResultHandle;
use Symfony\AI\Platform\Bridge\OpenAICodex\RawWebSocketResult;
use Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support\TestLogger;

final class RawWebSocketResultTest extends TestCase
{
    public function testStreamsDecodedEventsAndClosesAfterTerminalWithoutExtraReceive(): void
    {
        $messages = [
            WebsocketMessage::fromText(json_encode(['type' => 'response.output_text.delta', 'delta' => 'hi'], \JSON_THROW_ON_ERROR)),
            WebsocketMessage::fromText(json_encode(['type' => 'response.completed', 'response' => ['output' => []]], \JSON_THROW_ON_ERROR)),
        ];
        $index = 0;

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->exactly(2))
            ->method('receive')
            ->willReturnCallback(static function () use (&$index, $messages): WebsocketMessage {
                if ($index >= \count($messages)) {
                    self::fail('receive() must not be called after terminal event');
                }

                return $messages[$index++];
            });
        $connection->expects($this->once())->method('close');

        $raw = new RawWebSocketResult($connection, 5.0);
        $events = iterator_to_array($raw->getDataStream());

        $this->assertCount(2, $events);
        $this->assertSame('response.output_text.delta', $events[0]['type']);
        $this->assertInstanceOf(CodexWebSocketResultHandle::class, $raw->getObject());
    }

    public function testIdleTimeoutMapsToExplicitTransportException(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('receive')
            ->willThrowException(new CancelledException());
        $connection->expects($this->once())->method('close');

        $logger = new TestLogger();
        $raw = new RawWebSocketResult($connection, 0.01, $logger);

        try {
            iterator_to_array($raw->getDataStream());
            $this->fail('Expected idle timeout exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Codex WebSocket idle timeout.', $e->getMessage());
            $this->assertInstanceOf(CancelledException::class, $e->getPrevious());
        }

        $timeoutLogs = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'codex.websocket.io_timeout' === $record['message'],
        ));
        $this->assertCount(1, $timeoutLogs);
        $this->assertSame('receive', $timeoutLogs[0]['context']['phase']);
    }

    public function testFragmentedMessageBufferTimeoutIsBoundedAndInvalidatesCache(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $identity = CodexWebSocketCompatibilityFingerprint::fromContext(
            '0194dddd-bbbb-7ccc-8ddd-dddddddddddd',
            'openai-codex',
            'gpt-5.6-luna',
            'https://chatgpt.com/backend-api',
            '/codex/responses',
            'acct-1',
        );

        $queue = new Queue();
        // Incomplete first fragment: buffer() must wait for more data and is now timeout-bounded.
        $queue->pushAsync('{')->ignore();
        $message = WebsocketMessage::fromText(new ReadableIterableStream($queue->iterate()));

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())->method('receive')->willReturn($message);
        $connection->expects($this->once())->method('close');

        $entry = new CodexWebSocketCacheEntry($connection, $identity, time());
        $entry->continuation = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['input' => []],
            'resp-old',
            [],
        );
        $lease = new CodexWebSocketCacheLease($connection, true, true, false, $entry);
        $context = new CodexWebSocketCachedStreamContext($cache, $lease, ['input' => []]);

        $reflection = new \ReflectionClass($cache);
        $prop = $reflection->getProperty('entries');
        $prop->setValue($cache, [$identity->sessionKey => $entry]);

        $logger = new TestLogger();
        $raw = new RawWebSocketResult($connection, 0.01, $logger, cachedStreamContext: $context);

        // Amp's timeout watcher is unreferenced. Keep the loop referenced with
        // one owned safety watcher, then cancel it synchronously after buffer()
        // observes its expected timeout. If that timeout regresses, completing
        // the queue makes the test fail instead of deadlocking the worker.
        $safetyWatcher = EventLoop::delay(1.0, static function () use ($queue): void {
            $queue->complete();
        });

        try {
            iterator_to_array($raw->getDataStream());
            $this->fail('Expected message buffer timeout');
        } catch (\RuntimeException $e) {
            $this->assertSame('Codex WebSocket message buffer timeout.', $e->getMessage());
            $this->assertInstanceOf(CancelledException::class, $e->getPrevious());
        } finally {
            EventLoop::cancel($safetyWatcher);
            $queue->complete();
        }
        $this->assertNull($entry->continuation);
        $this->assertSame([], $prop->getValue($cache));

        $timeoutLogs = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'codex.websocket.io_timeout' === $record['message'],
        ));
        $this->assertCount(1, $timeoutLogs);
        $this->assertSame('buffer', $timeoutLogs[0]['context']['phase']);
        $this->assertTrue($timeoutLogs[0]['context']['cache_reused']);
    }

    public function testNonTextFrameIsProtocolError(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('receive')->willReturn(WebsocketMessage::fromBinary('binary'));
        $connection->expects($this->once())->method('close');

        $raw = new RawWebSocketResult($connection, 5.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex WebSocket frame was not a text message.');

        iterator_to_array($raw->getDataStream());
    }

    public function testAbortAndFinallyCloseConnectionOnce(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->never())->method('receive');
        $connection->expects($this->once())->method('close');

        $logger = new TestLogger();
        $raw = new RawWebSocketResult($connection, 5.0, $logger);
        $raw->abort();
        iterator_to_array($raw->getDataStream());
    }

    public function testCloseFailureLogsStructuredEvent(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->never())->method('receive');
        $connection->expects($this->once())->method('close')->willThrowException(new \RuntimeException('close failed'));

        $logger = new TestLogger();
        $raw = new RawWebSocketResult($connection, 5.0, $logger);
        $raw->abort();

        $this->assertCount(1, $logger->records);
        $this->assertSame('codex.websocket.close_failed', $logger->records[0]['message']);
        $this->assertSame('codex.websocket.close_failed', $logger->records[0]['context']['event_type']);
        $this->assertSame('raw_websocket_result', $logger->records[0]['context']['component']);
        $this->assertSame(\RuntimeException::class, $logger->records[0]['context']['exception_class']);
    }

    public function testCachedStreamFailureInvalidatesWithoutRetainingConnection(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $identity = CodexWebSocketCompatibilityFingerprint::fromContext(
            '0194bbbb-bbbb-7ccc-8ddd-bbbbbbbbbbbb',
            'openai-codex',
            'gpt-5.6-luna',
            'https://chatgpt.com/backend-api',
            '/codex/responses',
            'acct-1',
        );
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->method('receive')->willReturn(WebsocketMessage::fromText(json_encode(['type' => 'response.failed'], \JSON_THROW_ON_ERROR)));
        $connection->expects($this->once())->method('close');

        $entry = new CodexWebSocketCacheEntry($connection, $identity, time());
        $entry->continuation = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['input' => []],
            'resp-old',
            [],
        );
        $lease = new CodexWebSocketCacheLease($connection, true, true, false, $entry);
        $context = new CodexWebSocketCachedStreamContext($cache, $lease, ['input' => []]);

        $reflection = new \ReflectionClass($cache);
        $prop = $reflection->getProperty('entries');
        $prop->setValue($cache, [$identity->sessionKey => $entry]);

        $raw = new RawWebSocketResult($connection, 5.0, cachedStreamContext: $context);

        try {
            iterator_to_array($raw->getDataStream());
            $this->fail('Expected stream failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('non-success terminal', $e->getMessage());
        }

        $this->assertNull($entry->continuation);
        $this->assertSame([], $prop->getValue($cache));
    }

    public function testDestructorClosesConnectionWhenStreamNeverConsumed(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->never())->method('receive');
        $connection->expects($this->once())->method('close');

        $raw = new RawWebSocketResult($connection, 5.0);
        unset($raw);
    }

    public function testTerminalResponseOutputIsAuthoritativeOverStreamedOutputItems(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $identity = CodexWebSocketCompatibilityFingerprint::fromContext(
            '0194cccc-bbbb-7ccc-8ddd-cccccccccccc',
            'openai-codex',
            'gpt-5.6-luna',
            'https://chatgpt.com/backend-api',
            '/codex/responses',
            'acct-1',
        );

        $streamed = [
            'type' => 'function_call',
            'id' => 'fc_streamed',
            'call_id' => 'fc_streamed',
            'name' => 'streamed',
            'arguments' => '{}',
        ];
        $terminal = [
            'type' => 'function_call',
            'id' => 'fc_terminal',
            'call_id' => 'fc_terminal',
            'name' => 'terminal',
            'arguments' => '{}',
        ];

        $messages = [
            WebsocketMessage::fromText(json_encode([
                'type' => 'response.output_item.done',
                'item' => $streamed,
            ], \JSON_THROW_ON_ERROR)),
            WebsocketMessage::fromText(json_encode([
                'type' => 'response.completed',
                'response' => [
                    'id' => 'resp_terminal',
                    'output' => [$terminal],
                ],
            ], \JSON_THROW_ON_ERROR)),
        ];
        $index = 0;

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->exactly(2))
            ->method('receive')
            ->willReturnCallback(static function () use (&$index, $messages): WebsocketMessage {
                return $messages[$index++];
            });
        // Successful cached stream retains the connection (no close).
        $connection->expects($this->never())->method('close');

        $entry = new CodexWebSocketCacheEntry($connection, $identity, time());
        $lease = new CodexWebSocketCacheLease($connection, true, true, false, $entry);
        $fullRequestBody = [
            'model' => 'gpt-5.6-luna',
            'input' => [['role' => 'user', 'content' => 'first']],
            'stream' => true,
        ];
        $context = new CodexWebSocketCachedStreamContext($cache, $lease, $fullRequestBody);

        // Put the entry in the cache so release() keeps the retained connection path healthy.
        $reflection = new \ReflectionClass($cache);
        $prop = $reflection->getProperty('entries');
        $prop->setValue($cache, [$identity->sessionKey => $entry]);

        $raw = new RawWebSocketResult($connection, 5.0, cachedStreamContext: $context);
        iterator_to_array($raw->getDataStream());

        $this->assertNotNull($entry->continuation);
        $delta = $entry->continuation->buildDeltaRequest([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                $terminal,
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);

        $this->assertNotNull($delta);
        $this->assertSame('resp_terminal', $delta['previous_response_id']);
        $this->assertSame([['role' => 'user', 'content' => 'next']], $delta['input']);

        // Streamed item must not have been merged with terminal output (no duplicate baseline).
        $mergedWouldMatch = $entry->continuation->buildDeltaRequest([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                $streamed,
                $terminal,
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);
        $this->assertNull($mergedWouldMatch);
    }
}
