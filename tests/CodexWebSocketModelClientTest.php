<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Client\WebsocketConnection;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectorInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketHandshakeHeadersFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketModelClient;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketUrlResolver;
use Symfony\AI\Platform\Bridge\OpenAICodex\RawWebSocketResult;

final class CodexWebSocketModelClientTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testSendsResponseCreateFrameWithSharedBodyMapping(): void
    {
        $captured = new \stdClass();
        $captured->url = '';
        $captured->headers = [];
        $captured->frame = '';

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('sendText')
            ->willReturnCallback(static function (string $data) use ($captured): void {
                $captured->frame = $data;
            });

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->once())
            ->method('connect')
            ->willReturnCallback(static function (string $url, array $headers, float $timeout) use ($connection, $captured): WebsocketConnection {
                $captured->url = $url;
                $captured->headers = $headers;

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
        );

        $result = $client->request(
            new CodexModel('gpt-5.6-luna'),
            [
                'input' => [['role' => 'user', 'content' => 'hi']],
                'type' => 'malicious.override',
            ],
            ['temperature' => 0.5, 'type' => 'options.override'],
        );

        $this->assertInstanceOf(RawWebSocketResult::class, $result);
        $this->assertSame('wss://chatgpt.com/backend-api/codex/responses', $captured->url);
        $this->assertSame('Bearer access', $captured->headers['Authorization']);
        $this->assertSame('responses_websockets=2026-02-06', $captured->headers['OpenAI-Beta']);
        $this->assertSame('acct-1', $captured->headers['chatgpt-account-id']);
        $this->assertSame($captured->headers['session-id'], $captured->headers['x-client-request-id']);
        self::assertUuidVersion7($captured->headers['session-id']);

        $frame = json_decode($captured->frame, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($captured->headers['session-id'], $frame['prompt_cache_key']);
        $this->assertSame('response.create', $frame['type']);
        $this->assertSame('gpt-5.6-luna', $frame['model']);
        $this->assertSame('hi', $frame['input'][0]['content']);
        $this->assertFalse($frame['store']);
        $this->assertTrue($frame['stream']);
    }

    public function testSendFailureClosesConnection(): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('sendText')
            ->willThrowException(new \RuntimeException('send failed'));
        $connection->expects($this->once())->method('close');

        $connector = $this->createStub(CodexWebSocketConnectorInterface::class);
        $connector->method('connect')->willReturn($connection);

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'access',
            'acct-1',
        );

        try {
            $client->request(
                new CodexModel('gpt-5.6-luna'),
                ['input' => [['role' => 'user', 'content' => 'hi']]],
            );
            $this->fail('Expected transport exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Codex WebSocket request frame could not be sent.', $e->getMessage());
        }
    }

    public function testHandshake401RefreshesOnceAndRetriesWithNewBearerAndRequestIds(): void
    {
        $secret = 'LEAKED_HANDSHAKE_SECRET_9f3c2a1b';
        $sentFrame = '';
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('sendText')
            ->willReturnCallback(static function (string $data) use (&$sentFrame): void {
                $sentFrame = $data;
            });

        $connectCalls = [];
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->exactly(2))
            ->method('connect')
            ->willReturnCallback(function (string $url, array $headers, float $timeout) use (&$connectCalls, $connection, $secret) {
                $connectCalls[] = $headers;
                if (1 === \count($connectCalls)) {
                    throw $this->websocketConnectException(401, $secret);
                }

                return $connection;
            });

        $refresherCalls = 0;
        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'stale-access',
            'acct-1',
            accessTokenRefresher: static function () use (&$refresherCalls): string {
                ++$refresherCalls;

                return 'fresh-access-token';
            },
        );

        $client->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'hi']]],
        );

        $this->assertSame(1, $refresherCalls);
        $this->assertSame('Bearer stale-access', $connectCalls[0]['Authorization']);
        $this->assertSame('Bearer fresh-access-token', $connectCalls[1]['Authorization']);
        $this->assertNotSame($connectCalls[0]['session-id'], $connectCalls[1]['session-id']);
        $this->assertSame($connectCalls[1]['session-id'], $connectCalls[1]['x-client-request-id']);
        self::assertUuidVersion7($connectCalls[0]['session-id']);
        self::assertUuidVersion7($connectCalls[1]['session-id']);
        $frame = json_decode($sentFrame, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($connectCalls[1]['session-id'], $frame['prompt_cache_key']);
    }

    public function testHandshake401RetryPreservesExplicitPromptCacheKeyAcrossHandshakeAndFrame(): void
    {
        $sentFrame = '';
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('sendText')
            ->willReturnCallback(static function (string $data) use (&$sentFrame): void {
                $sentFrame = $data;
            });

        $connectCalls = [];
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->exactly(2))
            ->method('connect')
            ->willReturnCallback(function (string $url, array $headers, float $timeout) use (&$connectCalls, $connection) {
                $connectCalls[] = $headers;
                if (1 === \count($connectCalls)) {
                    throw $this->websocketConnectException(401);
                }

                return $connection;
            });

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'stale-access',
            'acct-1',
            accessTokenRefresher: static fn (): string => 'fresh-access-token',
        );

        $client->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'hi']]],
            ['prompt_cache_key' => 'session-run-keep'],
        );

        $this->assertSame('session-run-keep', $connectCalls[0]['session-id']);
        $this->assertSame('session-run-keep', $connectCalls[1]['session-id']);
        $this->assertSame('session-run-keep', $connectCalls[1]['x-client-request-id']);
        $frame = json_decode($sentFrame, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('session-run-keep', $frame['prompt_cache_key']);
    }

    public function testHandshake401DoesNotRetryWhenRefreshThrows(): void
    {
        $secret = 'LEAKED_REFRESH_FAIL_SECRET_7f3a91c2';
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->once())
            ->method('connect')
            ->willThrowException($this->websocketConnectException(401, $secret));

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'stale-access',
            'acct-1',
            accessTokenRefresher: static function (): string {
                throw new \RuntimeException('refresh exploded');
            },
        );

        try {
            $client->request(
                new CodexModel('gpt-5.6-luna'),
                ['input' => [['role' => 'user', 'content' => 'hi']]],
            );
            $this->fail('Expected handshake failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Codex WebSocket handshake failed with HTTP 401.', $e->getMessage());
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function testHandshake401DoesNotRetryWhenRefreshReturnsUnchangedToken(): void
    {
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->once())
            ->method('connect')
            ->willThrowException($this->websocketConnectException(401));

        $client = new CodexWebSocketModelClient(
            $connector,
            new CodexWebSocketUrlResolver(),
            new CodexWebSocketHandshakeHeadersFactory(),
            new CodexRequestBodyFactory(),
            'https://chatgpt.com/backend-api',
            'same-access',
            'acct-1',
            accessTokenRefresher: static fn (): string => 'same-access',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex WebSocket handshake failed with HTTP 401.');

        $client->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'hi']]],
        );
    }

    public function testPromptCacheKeyUsedForHandshakeAndFrame(): void
    {
        $providerKey = '0194a000-0000-7000-8000-000000000088';
        $captured = new \stdClass();
        $captured->headers = [];
        $captured->frame = '';

        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())
            ->method('sendText')
            ->willReturnCallback(static function (string $data) use ($captured): void {
                $captured->frame = $data;
            });

        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->once())
            ->method('connect')
            ->willReturnCallback(static function (string $url, array $headers, float $timeout) use ($connection, $captured): WebsocketConnection {
                $captured->headers = $headers;

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
        );

        $client->request(
            new CodexModel('gpt-5.6-luna'),
            ['input' => [['role' => 'user', 'content' => 'Hi']]],
            ['prompt_cache_key' => $providerKey],
        );

        $this->assertSame($providerKey, $captured->headers['session-id']);
        $this->assertSame($providerKey, $captured->headers['x-client-request-id']);
        $frame = json_decode($captured->frame, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame($providerKey, $frame['prompt_cache_key']);
    }

    private function websocketConnectException(int $status, string $secretMarker = ''): WebsocketConnectException
    {
        $request = new Request('wss://chatgpt.com/backend-api/codex/responses');
        $response = new Response('1.1', $status, null, [], null, $request);

        return new WebsocketConnectException('upgrade failed '.$secretMarker, $response);
    }
}
