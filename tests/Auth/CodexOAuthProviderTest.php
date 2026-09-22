<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthProvider;

#[AllowMockObjectsWithoutExpectations]
final class CodexOAuthProviderTest extends TestCase
{
    private CodexOAuthProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new CodexOAuthProvider(CodexOAuthConfig::providerOptions());
    }

    public function testAuthorizationUrlUsesLocalhostRedirectUri(): void
    {
        $url = $this->provider->getAuthorizationUrl();

        // The redirect_uri parameter must be http://localhost:<port>/auth/callback
        // to match the OpenAI Codex OAuth client registration. Hydra validates
        // exact string match — 127.0.0.1 would be rejected.
        $this->assertStringContainsString(
            'redirect_uri=http%3A%2F%2Flocalhost%3A1455%2Fauth%2Fcallback',
            $url,
            'Authorization URL must contain redirect_uri with localhost',
        );
    }

    public function testAuthorizationUrlOmitsApprovalPrompt(): void
    {
        $url = $this->provider->getAuthorizationUrl();

        // OpenAI's Hydra OAuth server rejects the 'approval_prompt' parameter
        // which league/oauth2-client injects by default. The custom provider
        // strips it from the authorization URL.
        $this->assertStringNotContainsString(
            'approval_prompt',
            $url,
            'Authorization URL must NOT contain approval_prompt parameter',
        );
    }

    public function testAuthorizationUrlContainsRequiredParams(): void
    {
        $url = $this->provider->getAuthorizationUrl();

        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=app_EMoamEEZ73f0CkXaXp7hrann', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('state=', $url);
    }

    public function testAuthorizationUrlContainsCustomParams(): void
    {
        $url = $this->provider->getAuthorizationUrl([
            'scope' => 'openid profile email offline_access',
            'originator' => 'symfony-ai-openai-codex',
            'codex_cli_simplified_flow' => 'true',
            'id_token_add_organizations' => 'true',
        ]);

        $this->assertStringContainsString('originator=symfony-ai-openai-codex', $url);
        $this->assertStringContainsString('codex_cli_simplified_flow=true', $url);
        $this->assertStringContainsString('id_token_add_organizations=true', $url);
    }

    public function testAccessTokenRequestOmitsEmptyClientSecret(): void
    {
        // Use a mock HTTP client that captures the outgoing request
        $capturedRequest = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->method('send')
            ->willReturnCallback(static function (RequestInterface $request) use (&$capturedRequest): Response {
                $capturedRequest = $request;

                return new Response(200, [], json_encode([
                    'access_token' => 'test-access-token',
                    'refresh_token' => 'test-refresh-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ]));
            });

        $provider = new CodexOAuthProvider(
            CodexOAuthConfig::providerOptions(),
            ['httpClient' => $httpClient],
        );

        $provider->setPkceCode('test-verifier-12345');

        try {
            $provider->getAccessToken('authorization_code', ['code' => 'test-auth-code']);
        } catch (\Throwable $e) {
            // We expect the mock to return a valid response; if it throws,
            // inspect the captured request before re-throwing
        }

        $this->assertNotNull($capturedRequest, 'A request should have been sent');

        $body = (string) $capturedRequest->getBody();

        // The client_secret parameter must NOT be present in the body
        // because Codex is a public OAuth client and Hydra rejects empty secrets
        $this->assertStringNotContainsString(
            'client_secret',
            $body,
            'Token request body must NOT contain client_secret when empty',
        );

        // Verify the required fields ARE present
        $this->assertStringContainsString('grant_type=authorization_code', $body);
        $this->assertStringContainsString('client_id=app_EMoamEEZ73f0CkXaXp7hrann', $body);
        $this->assertStringContainsString('code=test-auth-code', $body);
        $this->assertStringContainsString('code_verifier=test-verifier-12345', $body);
    }

    public function testAccessTokenRefreshRequestOmitsEmptyClientSecret(): void
    {
        $capturedRequest = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->method('send')
            ->willReturnCallback(static function (RequestInterface $request) use (&$capturedRequest): Response {
                $capturedRequest = $request;

                return new Response(200, [], json_encode([
                    'access_token' => 'refreshed-access-token',
                    'refresh_token' => 'new-refresh-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ]));
            });

        $provider = new CodexOAuthProvider(
            CodexOAuthConfig::providerOptions(),
            ['httpClient' => $httpClient],
        );

        try {
            $provider->getAccessToken('refresh_token', ['refresh_token' => 'old-refresh-token']);
        } catch (\Throwable $e) {
            // Inspect captured request before re-throwing
        }

        $this->assertNotNull($capturedRequest, 'A request should have been sent');

        $body = (string) $capturedRequest->getBody();

        $this->assertStringNotContainsString(
            'client_secret',
            $body,
            'Token refresh request body must NOT contain client_secret when empty',
        );

        $this->assertStringContainsString('grant_type=refresh_token', $body);
        $this->assertStringContainsString('client_id=app_EMoamEEZ73f0CkXaXp7hrann', $body);
        $this->assertStringContainsString('refresh_token=old-refresh-token', $body);
    }
}
