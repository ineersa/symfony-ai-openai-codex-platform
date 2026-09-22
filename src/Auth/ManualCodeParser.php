<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

/**
 * Parses manual user-pasted input for the OAuth authorization code.
 *
 * Accepts:
 *  - Full redirect URL (http://127.0.0.1:1455/auth/callback?code=...&state=...)
 *  - Query-string fragment (code=xxx&state=yyy)
 *  - "code#state" format (common when URL hash is stripped)
 *  - Bare authorization code
 *
 * Mirrors pi-mono's parseAuthorizationInput() in openai-codex.ts.
 */
final class ManualCodeParser
{
    /**
     * Parse manual input into an optional code and optional state.
     *
     * @return array{code: string|null, state: string|null}
     */
    public static function parse(string $input): array
    {
        $value = trim($input);
        if ('' === $value) {
            return ['code' => null, 'state' => null];
        }

        // Try as a full URL first
        if (str_contains($value, '://')) {
            $parts = parse_url($value);
            if (false !== $parts && isset($parts['query'])) {
                parse_str($parts['query'], $params);

                return [
                    'code' => isset($params['code']) ? (string) $params['code'] : null,
                    'state' => isset($params['state']) ? (string) $params['state'] : null,
                ];
            }
        }

        // Try "code#state" format (URL hash fragment)
        if (str_contains($value, '#')) {
            [$code, $state] = explode('#', $value, 2);

            return [
                'code' => '' !== $code ? $code : null,
                'state' => '' !== $state ? $state : null,
            ];
        }

        // Try "code=xxx&state=yyy" format
        if (str_contains($value, 'code=')) {
            parse_str($value, $params);

            return [
                'code' => isset($params['code']) ? (string) $params['code'] : null,
                'state' => isset($params['state']) ? (string) $params['state'] : null,
            ];
        }

        // Fallback: bare code
        return ['code' => $value, 'state' => null];
    }
}
