<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

/**
 * Constants and configuration for the OpenAI Codex OAuth PKCE flow.
 *
 * Mirrors the pi-mono openai-codex.ts configuration:
 *   packages/ai/src/utils/oauth/openai-codex.ts
 *
 * @see https://auth.openai.com/oauth/authorize
 * @see https://auth.openai.com/oauth/token
 */
final class CodexOAuthConfig
{
    /**
     * OpenAI OAuth client ID for Codex / ChatGPT subscription.
     * Shared across Codex CLI, Roo Code, Pi, and other third-party tools.
     */
    public const string CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    /** OpenAI OAuth authorization endpoint. */
    public const string AUTHORIZE_URL = 'https://auth.openai.com/oauth/authorize';

    /** OpenAI OAuth token endpoint. */
    public const string TOKEN_URL = 'https://auth.openai.com/oauth/token';

    /** OAuth scopes requested for Codex access. */
    public const string SCOPE = 'openid profile email offline_access';

    /** JWT claim path for the chatgpt_account_id. */
    public const string JWT_CLAIM_PATH = 'https://api.openai.com/auth';

    /** Default local TCP port for the OAuth callback server. */
    public const int DEFAULT_PORT = 1455;

    /** Default timeout in seconds for the full login flow. */
    public const int DEFAULT_TIMEOUT = 300;

    /** Provider key used in auth.json storage. */
    public const string PROVIDER_KEY = 'openai-codex';

    /** Originator value sent to the OpenAI authorize endpoint. */
    public const string ORIGINATOR = 'symfony-ai-openai-codex';

    public function __construct(
        public readonly string $originator = self::ORIGINATOR,
        public readonly string $displayName = 'OpenAI Codex',
        public readonly string $commandName = 'auth:codex',
    ) {
    }

    /**
     * Redirect URI for the given port.
     */
    public static function redirectUriForPort(int $port = self::DEFAULT_PORT): string
    {
        return \sprintf('http://localhost:%d/auth/callback', $port);
    }

    /**
     * Provider options array for the given port.
     *
     * Centralised so both CodexOAuthService and CodexTokenRefresher
     * share the same configuration.
     *
     * Note: the resulting {@see CodexOAuthProvider} filters out the
     * empty client_secret from token requests; OpenAI's Hydra OAuth
     * server rejects the field for this public client registration.
     *
     * @return array<string, mixed>
     */
    public static function providerOptions(int $port = self::DEFAULT_PORT): array
    {
        return [
            'clientId' => self::CLIENT_ID,
            'clientSecret' => '',
            'redirectUri' => self::redirectUriForPort($port),
            'urlAuthorize' => self::AUTHORIZE_URL,
            'urlAccessToken' => self::TOKEN_URL,
            'urlResourceOwnerDetails' => '',
            'pkceMethod' => 'S256',
        ];
    }
}
