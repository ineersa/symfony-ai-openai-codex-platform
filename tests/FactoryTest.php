<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Websocket\Client\WebsocketConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectorInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory;
use Symfony\AI\Platform\Bridge\OpenAICodex\Result\CancellableRawResultInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FactoryTest extends TestCase
{
    public function testSseFactoryPassesHostIdentityAndConsumesConfiguredOptions(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->assertSame('POST', $method);
            $this->assertSame('originator: host-cli', $options['normalized_headers']['originator'][0]);
            $this->assertSame('User-Agent: host-cli/1', $options['normalized_headers']['user-agent'][0]);
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('host_run_id', $body);
            $this->assertSame('visible', $body['metadata']['label']);

            return new MockResponse();
        });
        $provider = Factory::createProvider(
            accessToken: 'token', accountId: 'account', httpClient: $http,
            originator: 'host-cli', userAgent: 'host-cli/1',
            internalOptions: ['host_run_id'], transport: CodexTransportEnum::Sse,
        );
        $provider->invoke(new CodexModel('gpt-5.5'), new MessageBag(Message::ofUser('hello')), ['host_run_id' => 'private', 'metadata' => ['label' => 'visible']]);
        $this->assertSame(1, $http->getRequestsCount());
    }

    #[DataProvider('websocketTransports')]
    public function testWebsocketFactoryPassesHostIdentityAndSupportsAbort(CodexTransportEnum $transport): void
    {
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())->method('sendText')->with($this->callback(function (string $frame): bool {
            $body = json_decode($frame, true, flags: \JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('host_run_id', $body);
            $this->assertSame('response.create', $body['type']);

            return true;
        }));
        $connection->expects($this->once())->method('close');
        $connector = $this->createMock(CodexWebSocketConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with(
            'wss://chatgpt.com/backend-api/codex/responses',
            $this->callback(function (array $headers): bool {
                $this->assertSame('host-cli', $headers['originator']);
                $this->assertSame('host-cli/1', $headers['User-Agent']);

                return true;
            }),
            $this->anything(),
        )->willReturn($connection);
        $cache = new CodexWebSocketConnectionCache();
        $provider = Factory::createProvider(
            accessToken: 'token', accountId: 'account', originator: 'host-cli',
            transport: $transport, websocketConnector: $connector, websocketConnectionCache: $cache,
            userAgent: 'host-cli/1', internalOptions: ['host_run_id'],
        );
        try {
            $result = $provider->invoke(new CodexModel('gpt-5.5'), new MessageBag(Message::ofUser('hello')), [
                'host_run_id' => 'private', 'prompt_cache_key' => '0194ffff-bbbb-7ccc-8ddd-444444444444',
            ])->getRawResult();
            $this->assertInstanceOf(CancellableRawResultInterface::class, $result);
            $result->abort();
        } finally {
            $cache->closeAll();
        }
    }

    /** @return iterable<string, array{CodexTransportEnum}> */
    public static function websocketTransports(): iterable
    {
        yield 'websocket' => [CodexTransportEnum::Websocket];
        yield 'cached websocket' => [CodexTransportEnum::WebsocketCached];
    }
}
