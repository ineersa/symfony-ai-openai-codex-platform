<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrates the OpenAI Codex OAuth PKCE login flow.
 *
 * Wraps league/oauth2-client for PKCE generation, authorization URL
 * construction, and token exchange, while the CLI-specific pieces
 * (callback server, browser launch, manual paste) are handled by
 * dedicated callback and browser helpers.
 */
final class CodexOAuthService
{
    public function __construct(
        private CodexAuthStorageInterface $storage,
        private ?CodexTokenRefresher $tokenRefresher = null,
        private CodexOAuthConfig $config = new CodexOAuthConfig(),
        private LocalCallbackServer $callbackServer = new LocalCallbackServer(),
        private ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * Run the full OAuth PKCE login flow.
     *
     * 1. Build the authorization URL with PKCE challenge
     * 2. Start the local callback server on 127.0.0.1:$port
     * 3. Print the URL and (once server binds) try to open the browser
     * 4. Race callback vs manual paste input
     * 5. Exchange the authorization code for tokens
     * 6. Extract the chatgpt_account_id from the JWT
     * 7. Persist credentials to auth.json
     * 8. Return the record (callers must NOT echo raw tokens)
     *
     * @throws \RuntimeException on any step failure
     */
    public function login(
        SymfonyStyle $io,
        bool $noBrowser = false,
        int $timeout = CodexOAuthConfig::DEFAULT_TIMEOUT,
        int $port = CodexOAuthConfig::DEFAULT_PORT,
        string $providerKey = CodexOAuthConfig::PROVIDER_KEY,
    ): CodexAuthRecord {
        $provider = $this->createProvider($port);
        $authUrl = $provider->getAuthorizationUrl([
            'scope' => CodexOAuthConfig::SCOPE,
            'originator' => $this->config->originator,
            'codex_cli_simplified_flow' => 'true',
            'id_token_add_organizations' => 'true',
        ]);

        $expectedState = $provider->getState();
        $pkceVerifier = $provider->getPkceCode();

        // Print instructions BEFORE starting the callback server, so the
        // user has the URL even if the server bind fails.
        $io->writeln('');
        $io->writeln('  <info>'.$this->config->displayName.' Authorization</info>');
        $io->writeln('');
        $io->writeln('  A browser window should open. If not, visit:');
        $io->writeln(\sprintf('  <href=%s>%s</>', $authUrl, $authUrl));
        $io->writeln('');

        // Start server: the $afterListen callback opens the browser AFTER
        // the TCP port is bound, so a fast auto-redirect can reach us.
        $callbackResult = $this->callbackServer->waitForCallback(
            $expectedState,
            (float) $timeout,
            $port,
            static function () use ($noBrowser, $authUrl): void {
                if (!$noBrowser) {
                    BrowserLauncher::open($authUrl);
                }
            },
        );

        // Try manual paste as fallback
        if (null === $callbackResult) {
            $io->writeln('  Could not detect browser callback automatically.');
            $io->writeln('  Paste the redirect URL (or just the authorization code) below:');
            $io->writeln('');

            $input = (string) $io->ask('  Authorization code / URL', null, static function (?string $v) {
                if (null === $v || '' === trim($v)) {
                    throw new \RuntimeException('Authorization input is required.');
                }

                return trim($v);
            });

            $parsed = ManualCodeParser::parse($input);
            if (null !== $parsed['state'] && $parsed['state'] !== $expectedState) {
                throw new \RuntimeException('State mismatch in manual paste input. Please try again.');
            }

            $code = $parsed['code'];
        } else {
            $code = $callbackResult['code'];
            $io->writeln('  <info>✓</info> Authorization callback received.');
        }

        if (null === $code || '' === $code) {
            throw new \RuntimeException('No authorization code obtained.');
        }

        // Exchange authorization code for tokens
        try {
            $provider->setPkceCode($pkceVerifier);
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (IdentityProviderException $e) {
            throw new \RuntimeException(\sprintf('Token exchange failed: %s', $e->getMessage()), previous: $e);
        }

        $accessToken = $token->getToken();
        $refreshToken = $token->getRefreshToken();
        $expires = $token->getExpires();

        if ('' === $accessToken || null === $refreshToken || null === $expires) {
            throw new \RuntimeException('Token exchange response missing required fields (access, refresh, expires).');
        }

        // Extract account ID from JWT
        $accountId = CodexAccountIdExtractor::extract($accessToken);
        if (null === $accountId) {
            throw new \RuntimeException('Failed to extract chatgpt_account_id from the access token JWT.');
        }

        // Persist
        $record = new CodexAuthRecord(
            access: $accessToken,
            refresh: $refreshToken,
            expires: $expires,
            accountId: $accountId,
        );

        $this->storage->saveCredentials($providerKey, $record);

        return $record;
    }

    /**
     * Refresh stored credentials for the given provider key.
     *
     * Loads the stored refresh token, exchanges it for new tokens
     * via {@see CodexTokenRefresher}, validates the account ID,
     * and persists the result.
     *
     * @throws \RuntimeException when no stored credentials, refresh fails, or account ID changes
     */
    public function refreshCredentials(string $providerKey = CodexOAuthConfig::PROVIDER_KEY): CodexAuthRecord
    {
        if (null === $this->tokenRefresher) {
            throw new \RuntimeException('Token refresh is not available (no refresher configured).');
        }

        $stored = $this->storage->loadCredentialsRaw($providerKey);

        if (null === $stored) {
            $hint = CodexOAuthConfig::authCommandHintForProviderKey($providerKey, $this->config->commandName);
            throw new \RuntimeException(\sprintf('No stored Codex credentials found. Run %s first.', $hint));
        }

        try {
            $fresh = $this->tokenRefresher->refresh($stored->refresh, $stored->accountId);
        } catch (\Throwable $e) {
            $hint = CodexOAuthConfig::authCommandHintForProviderKey($providerKey, $this->config->commandName);

            throw new \RuntimeException("Token refresh failed for stored Codex credentials. Run {$hint} to re-authenticate.", previous: $e);
        }

        $this->storage->saveCredentials($providerKey, $fresh);

        return $fresh;
    }

    /**
     * Get a configured CodexOAuthProvider for the Codex OAuth PKCE flow.
     *
     * Uses the custom provider which strips the league default 'approval_prompt'
     * parameter and omits the empty 'client_secret' from token requests — both
     * required by OpenAI's Hydra OAuth server.
     */
    private function createProvider(int $port = CodexOAuthConfig::DEFAULT_PORT): CodexOAuthProvider
    {
        $provider = new CodexOAuthProvider(CodexOAuthConfig::providerOptions($port));
        if (null !== $this->httpClient) {
            $provider->setHttpClient($this->httpClient);
        }

        return $provider;
    }
}
