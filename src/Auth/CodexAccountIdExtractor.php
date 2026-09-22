<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

/**
 * Extracts the chatgpt_account_id from an OpenAI-signed JWT access token.
 *
 * The account ID is stored at the claim path:
 *   payload["https://api.openai.com/auth"]["chatgpt_account_id"]
 *
 * @see https://auth.openai.com/oauth/authorize
 */
final class CodexAccountIdExtractor
{
    /**
     * Extract the chatgpt_account_id from a JWT access token.
     *
     * @return non-empty-string|null The account ID, or null if missing/invalid
     */
    public static function extract(string $accessToken): ?string
    {
        $parts = explode('.', $accessToken);
        if (3 !== \count($parts)) {
            return null;
        }

        $payloadB64 = $parts[1];
        if ('' === $payloadB64) {
            return null;
        }

        $decoded = self::base64urlDecode($payloadB64);
        if (null === $decoded) {
            return null;
        }

        // Invalid JSON is an invalid credential, not an exceptional parser failure.
        $payload = json_decode($decoded, true, 8);
        if (!\is_array($payload)) {
            return null;
        }

        $auth = $payload[CodexOAuthConfig::JWT_CLAIM_PATH] ?? null;
        if (!\is_array($auth)) {
            return null;
        }

        $accountId = $auth['chatgpt_account_id'] ?? null;

        return \is_string($accountId) && '' !== $accountId ? $accountId : null;
    }

    /**
     * Base64url-decode a string (RFC 4648 §5).
     *
     * Replaces URL-safe characters with standard base64 and strips padding
     * before decoding.
     */
    private static function base64urlDecode(string $encoded): ?string
    {
        $remainder = \strlen($encoded) % 4;
        if (0 !== $remainder) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return \is_string($decoded) ? $decoded : null;
    }
}
