<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\Websocket\Client\WebsocketConnection;

/**
 * Result of acquiring a Codex WebSocket for one request.
 *
 * Valid flag combinations:
 * - cached=false, oneShot=true, entry=null: plain websocket or busy one-shot (always closed on release/failure).
 * - cached=true, oneShot=false, entry non-null: session-cached socket (may be retained after success).
 * - cached=false, oneShot=true, entry=null with reused=false: busy concurrent one-shot while cache entry stays busy.
 */
final readonly class CodexWebSocketCacheLease
{
    public function __construct(
        public WebsocketConnection $connection,
        public bool $reused,
        public bool $cached,
        public bool $oneShot,
        public ?CodexWebSocketCacheEntry $entry,
    ) {
    }
}
