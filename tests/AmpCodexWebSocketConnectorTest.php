<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455Connector;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\AmpCodexWebSocketConnector;
use Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support\PendingOperation;

use function Amp\async;

final class AmpCodexWebSocketConnectorTest extends TestCase
{
    public function testCancellationInterruptsPendingHandshakeWithoutMappingItToTimeout(): void
    {
        $pending = new PendingOperation();
        $delegate = $this->createMock(DelegateHttpClient::class);
        $delegate->expects($this->once())->method('request')->willReturnCallback(
            static function (Request $request, Cancellation $cancellation) use ($pending): Response {
                $pending->wait($cancellation);
                self::fail('Handshake must be cancelled before a response arrives.');
            },
        );
        $connector = new AmpCodexWebSocketConnector(new Rfc6455Connector(httpClient: new HttpClient($delegate, [])));
        $source = new DeferredCancellation();
        $future = async(static fn () => $connector->connect('ws://localhost/responses', [], 120.0, $source->getCancellation()));
        try {
            $pending->ready->getFuture()->await(new TimeoutCancellation(2.0));
            $source->cancel();
            try {
                $future->await(new TimeoutCancellation(2.0));
                $this->fail('Expected run cancellation.');
            } catch (CancelledException) {
                $this->assertTrue($source->isCancelled());
                $this->assertTrue($pending->finished);
            }
        } finally {
            $pending->close();
            \Amp\Future\awaitAll([$future]);
        }
    }
}
