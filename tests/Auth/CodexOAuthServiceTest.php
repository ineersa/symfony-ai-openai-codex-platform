<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support\InMemoryAuthStorage;

final class CodexOAuthServiceTest extends TestCase
{
    private InMemoryAuthStorage $storage;
    private CodexTokenRefresher $refresher;

    protected function setUp(): void
    {
        $this->storage = new InMemoryAuthStorage();
        $this->refresher = new CodexTokenRefresher(httpClient: new Client([
            'handler' => HandlerStack::create(new MockHandler([new Response(400, [], '{"error":"invalid_grant"}')])),
        ]));
    }

    public function testRefreshCredentialsThrowsWhenNoStoredCredentials(): void
    {
        $service = new CodexOAuthService($this->storage, $this->refresher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No stored Codex credentials found');

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsProfileKeyHintInErrorMessage(): void
    {
        $service = new CodexOAuthService($this->storage, $this->refresher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bin/console auth:codex --auth-profile=work');

        $service->refreshCredentials('openai-codex-work');
    }

    public function testRefreshCredentialsDefaultKeyHasNoProfileHint(): void
    {
        $service = new CodexOAuthService($this->storage, $this->refresher);

        try {
            $service->refreshCredentials();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bin/console auth:codex', $e->getMessage());
            $this->assertStringNotContainsString('--auth-profile=', $e->getMessage());
        }
    }

    public function testRefreshCredentialsWithCustomProviderKeyIsIsolated(): void
    {
        // Store under work key only
        $valid = new CodexAuthRecord(
            access: 'work-access',
            refresh: 'work-refresh',
            expires: time() + 3600,
            accountId: 'work-account',
        );
        $this->storage->saveCredentials('openai-codex-work', $valid);

        $service = new CodexOAuthService($this->storage, $this->refresher);

        // Default key has no stored credentials
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No stored Codex credentials found');

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsWithCustomProviderKeyDoesNotFallbackToDefault(): void
    {
        // Store under default key only
        $defaultRecord = new CodexAuthRecord(
            access: 'default-access',
            refresh: 'default-refresh',
            expires: time() + 3600,
            accountId: 'default-account',
        );
        $this->storage->saveCredentials('openai-codex', $defaultRecord);

        // Service should fail when asked for non-existent key
        $service = new CodexOAuthService($this->storage, $this->refresher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No stored Codex credentials found');

        $service->refreshCredentials('openai-codex-non-existent');
    }

    public function testRefreshCredentialsThrowsWhenRefresherNotConfigured(): void
    {
        $expired = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'expired-refresh-token',
            expires: time() - 3600,
            accountId: 'expired-account',
        );
        $this->storage->saveCredentials('openai-codex', $expired);

        $service = new CodexOAuthService($this->storage); // no refresher

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no refresher configured');

        $service->refreshCredentials();
    }

    public function testRefreshCredentialsWithStoredExpiredDataAttemptsRefresh(): void
    {
        $expired = new CodexAuthRecord(
            access: 'expired-access',
            refresh: 'expired-refresh-token',
            expires: time() - 3600,
            accountId: 'expired-account',
        );
        $this->storage->saveCredentials('openai-codex', $expired);

        $service = new CodexOAuthService($this->storage, $this->refresher);

        // Mocked identity provider rejects the expired refresh token.
        $this->expectException(\RuntimeException::class);

        $service->refreshCredentials();
    }

    public function testCodexAuthRecordIsExpiredWithSeconds(): void
    {
        // Record expires 30 seconds from now (Unix seconds)
        $record = new CodexAuthRecord(
            access: 'tok',
            refresh: 'ref',
            expires: time() + 30,
            accountId: 'acct',
        );

        $this->assertTrue($record->isExpired(60), 'buffer 60 > 30 remaining = expired');
        $this->assertFalse($record->isExpired(0), 'no buffer, 30 remaining = not expired');
    }

    public function testRefreshCredentialsProfileKeyShowsProfileHintOnRefreshFailure(): void
    {
        // Store a record under the work profile key so loadCredentialsRaw succeeds,
        // then use a failing refresher so the refresh attempt throws.
        $valid = new CodexAuthRecord(
            access: 'work-access',
            refresh: 'work-refresh',
            expires: time() + 3600,
            accountId: 'work-account',
        );
        $this->storage->saveCredentials('openai-codex-work', $valid);

        $failingRefresher = new class extends CodexTokenRefresher {
            public function refresh(string $refreshToken, string $expectedAccountId): CodexAuthRecord
            {
                throw new \RuntimeException('Simulated refresh failure.');
            }
        };

        $service = new CodexOAuthService($this->storage, $failingRefresher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bin/console auth:codex --auth-profile=work');

        $service->refreshCredentials('openai-codex-work');
    }

    public function testRefreshCredentialsDefaultKeyShowsNoProfileHintOnRefreshFailure(): void
    {
        $valid = new CodexAuthRecord(
            access: 'default-access',
            refresh: 'default-refresh',
            expires: time() + 3600,
            accountId: 'default-account',
        );
        $this->storage->saveCredentials('openai-codex', $valid);

        $failingRefresher = new class extends CodexTokenRefresher {
            public function refresh(string $refreshToken, string $expectedAccountId): CodexAuthRecord
            {
                throw new \RuntimeException('Simulated refresh failure.');
            }
        };

        $service = new CodexOAuthService($this->storage, $failingRefresher);

        try {
            $service->refreshCredentials();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bin/console auth:codex', $e->getMessage());
            $this->assertStringNotContainsString('--auth-profile=', $e->getMessage());
        }
    }
}
