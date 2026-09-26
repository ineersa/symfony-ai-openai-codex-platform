<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

/**
 * Exchanges a refresh token for fresh OAuth credentials.
 *
 * Centralised refresh logic used by host credential storage
 * (auto-refresh on read) and {@see CodexOAuthService}
 * (explicit refresh via auth:codex --refresh).
 *
 * The account ID extracted from the refreshed JWT is validated against
 * the previously stored account ID to detect credential theft or
 * unexpected account changes.
 */
class CodexTokenRefresher
{
    public function __construct(
        private int $port = CodexOAuthConfig::DEFAULT_PORT,
        private ?ClientInterface $httpClient = null,
        private CodexOAuthConfig $config = new CodexOAuthConfig(),
    ) {
    }

    /**
     * Exchange a refresh token for a new credential record.
     *
     * @param non-empty-string $refreshToken      The saved refresh token
     * @param non-empty-string $expectedAccountId Previously stored account ID for cross-check
     *
     * @throws \RuntimeException on network failure, missing fields, or account ID mismatch
     */
    public function refresh(string $refreshToken, string $expectedAccountId): CodexAuthRecord
    {
        $provider = new CodexOAuthProvider(CodexOAuthConfig::providerOptions($this->port));
        if (null !== $this->httpClient) {
            $provider->setHttpClient($this->httpClient);
        }
        $hint = 'bin/console '.$this->config->commandName;

        try {
            $token = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
            ]);
        } catch (IdentityProviderException $e) {
            throw new \RuntimeException(\sprintf('Token refresh failed. Run %s to re-authenticate.', $hint), previous: $e);
        }

        $accessToken = $token->getToken();
        $newRefreshToken = $token->getRefreshToken();
        $expires = $token->getExpires();

        if ('' === $accessToken || null === $newRefreshToken || null === $expires) {
            throw new \RuntimeException('Token refresh response missing required fields (access, refresh, expires).');
        }

        $accountId = CodexAccountIdExtractor::extract($accessToken);
        if (null === $accountId) {
            throw new \RuntimeException(\sprintf('Failed to extract account ID from refreshed token. Run %s to re-authenticate.', $hint));
        }

        if ($accountId !== $expectedAccountId) {
            throw new \RuntimeException(\sprintf('Account ID changed after token refresh. Run %s to re-authenticate.', $hint));
        }

        return new CodexAuthRecord(
            access: $accessToken,
            refresh: $newRefreshToken,
            expires: $expires,
            accountId: $accountId,
        );
    }
}
