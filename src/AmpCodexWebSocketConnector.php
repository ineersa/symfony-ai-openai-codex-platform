<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;

final class AmpCodexWebSocketConnector implements CodexWebSocketConnectorInterface
{
    public function __construct(
        private readonly Rfc6455Connector $connector = new Rfc6455Connector(),
    ) {
    }

    public function connect(string $websocketUrl, array $headers, float $connectTimeoutSeconds): WebsocketConnection
    {
        $handshake = new WebsocketHandshake($websocketUrl, $headers);
        $handshake = $handshake->withTcpConnectTimeout($connectTimeoutSeconds);
        $handshake = $handshake->withTlsHandshakeTimeout($connectTimeoutSeconds);

        try {
            return $this->connector->connect($handshake, new TimeoutCancellation($connectTimeoutSeconds));
        } catch (CancelledException $e) {
            throw new \RuntimeException('Codex WebSocket connect timeout.', previous: $e);
        }
    }
}
