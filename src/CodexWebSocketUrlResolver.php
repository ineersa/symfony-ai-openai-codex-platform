<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Derives the Codex WebSocket URL from the configured HTTP base URL and responses path.
 */
final class CodexWebSocketUrlResolver
{
    public function resolve(string $baseUrl, string $responsesPath): string
    {
        $base = rtrim($baseUrl, '/');
        $path = '/'.ltrim($responsesPath, '/');

        if (str_starts_with($base, 'https://')) {
            return 'wss://'.substr($base, 8).$path;
        }

        if (str_starts_with($base, 'http://')) {
            return 'ws://'.substr($base, 7).$path;
        }

        throw new \InvalidArgumentException(\sprintf('Unsupported Codex base URL scheme for WebSocket: %s', $baseUrl));
    }
}
