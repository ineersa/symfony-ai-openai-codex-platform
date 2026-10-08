<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\Cancellation;
use Amp\Websocket\Client\WebsocketConnection;

/**
 * External async WebSocket protocol seam for deterministic tests.
 */
interface CodexWebSocketConnectorInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function connect(string $websocketUrl, array $headers, float $connectTimeoutSeconds, ?Cancellation $cancellation = null): WebsocketConnection;
}
