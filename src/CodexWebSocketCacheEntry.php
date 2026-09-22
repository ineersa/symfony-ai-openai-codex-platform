<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\Websocket\Client\WebsocketConnection;

/**
 * One session-scoped cached WebSocket and its continuation state.
 */
final class CodexWebSocketCacheEntry
{
    public bool $busy = true;

    public ?CodexWebSocketContinuationState $continuation = null;

    /** Timestamp when the entry last became idle after a successful cached lease release; null while busy or before first release. */
    public ?int $idleSince = null;

    public function __construct(
        public readonly WebsocketConnection $connection,
        public readonly CodexWebSocketCompatibilityFingerprint $identity,
        public readonly int $createdAt,
    ) {
    }
}
