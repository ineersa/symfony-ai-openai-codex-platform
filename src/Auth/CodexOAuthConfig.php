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
     * Map a profile name to a provider storage key.
     *
     * When profile is null, empty, or whitespace-only, returns the default
     * PROVIDER_KEY without throwing. When a non-empty profile is provided,
     * returns 'openai-codex-<normalized-profile>'.
     *
     * The profile name is validated for safe characters:
     * only lowercase letters, digits, hyphens, and underscores are allowed.
     * Dot ("."), double-dot (".."), or anything containing slashes,
     * backslashes, or whitespace is rejected with InvalidArgumentException.
     *
     * @throws \InvalidArgumentException when profile name is invalid
     */
    public static function providerKeyForProfile(?string $profile): string
    {
        if (null === $profile || '' === trim($profile)) {
            return self::PROVIDER_KEY;
        }

        $normalized = strtolower(trim($profile));

        if ('' === $normalized || !preg_match('/^[a-z0-9_-]+$/', $normalized)) {
            throw new \InvalidArgumentException(\sprintf('Invalid profile name "%s". Only lowercase letters, digits, hyphens, and underscores are allowed.', $profile));
        }

        return self::PROVIDER_KEY.'-'.$normalized;
    }

    /**
     * Extract the profile name from a provider storage key.
     *
     * Returns null for:
     *  - The default key 'openai-codex'
     *  - A malformed key that does not match 'openai-codex-<valid-profile>'
     *  - 'openai-codex-' with no profile suffix
     *
     * Returns the profile string for keys like 'openai-codex-work' => 'work'.
     */
    public static function profileFromProviderKey(string $providerKey): ?string
    {
        if (self::PROVIDER_KEY === $providerKey) {
            return null;
        }

        $prefix = self::PROVIDER_KEY.'-';

        if (!str_starts_with($providerKey, $prefix)) {
            return null;
        }

        $suffix = substr($providerKey, \strlen($prefix));

        // Reject empty suffix (e.g. 'openai-codex-')
        if ('' === $suffix || '' === trim($suffix)) {
            return null;
        }

        // Validate suffix matches allowed profile characters
        if (!preg_match('/^[a-z0-9_-]+$/', $suffix)) {
            return null;
        }

        return $suffix;
    }

    /**
     * Build a CLI command hint for the given provider storage key.
     *
     * Returns 'bin/console auth:codex' for the default key or custom keys,
     * and appends ' --auth-profile=<profile>' only when the key follows the
     * 'openai-codex-<valid-profile>' pattern.
     */
    public static function authCommandHintForProviderKey(string $providerKey, string $commandName = 'auth:codex'): string
    {
        $hint = 'bin/console '.$commandName;

        $profile = self::profileFromProviderKey($providerKey);

        if (null !== $profile) {
            $hint .= \sprintf(' --auth-profile=%s', $profile);
        }

        return $hint;
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
