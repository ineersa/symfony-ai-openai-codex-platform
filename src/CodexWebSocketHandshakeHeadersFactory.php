<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Builds Codex WebSocket handshake headers (not valid on SSE HTTP POST).
 */
final class CodexWebSocketHandshakeHeadersFactory
{
    public const string OPENAI_BETA_WEBSOCKETS = 'responses_websockets=2026-02-06';

    /**
     * @return array<string, string>
     */
    public function create(
        string $accessToken,
        string $accountId,
        string $originator,
        string $requestId,
        string $userAgent = 'symfony-ai-openai-codex',
    ): array {
        return [
            'Authorization' => 'Bearer '.$accessToken,
            'chatgpt-account-id' => $accountId,
            'originator' => $originator,
            'User-Agent' => $userAgent,
            'session-id' => $requestId,
            'x-client-request-id' => $requestId,
            'OpenAI-Beta' => self::OPENAI_BETA_WEBSOCKETS,
        ];
    }
}
