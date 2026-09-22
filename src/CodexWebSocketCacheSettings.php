<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Idle and maximum-age limits for session-cached Codex WebSocket connections.
 */
final readonly class CodexWebSocketCacheSettings
{
    public const int DEFAULT_IDLE_TTL_SECONDS = 300;
    public const int DEFAULT_MAX_AGE_SECONDS = 3300;

    public function __construct(
        public int $idleTtlSeconds = self::DEFAULT_IDLE_TTL_SECONDS,
        public int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
    ) {
        if ($this->idleTtlSeconds < 1) {
            throw new \InvalidArgumentException('Codex WebSocket cache idle TTL must be at least 1 second.');
        }
        if ($this->maxAgeSeconds < 1) {
            throw new \InvalidArgumentException('Codex WebSocket cache max age must be at least 1 second.');
        }
    }
}
