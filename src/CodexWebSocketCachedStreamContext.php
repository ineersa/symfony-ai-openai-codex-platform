<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Optional cache lifecycle for a streaming Codex WebSocket result.
 */
final readonly class CodexWebSocketCachedStreamContext
{
    /**
     * @param array<string, mixed> $fullRequestBody
     */
    public function __construct(
        public CodexWebSocketConnectionCache $cache,
        public CodexWebSocketCacheLease $lease,
        public array $fullRequestBody,
    ) {
    }
}
