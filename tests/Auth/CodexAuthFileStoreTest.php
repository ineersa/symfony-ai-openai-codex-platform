<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;

final class CodexAuthFileStoreTest extends TestCase
{
    public function testSavesCodexWithoutOverwritingOtherProviderAndProtectsFile(): void
    {
        $directory = sys_get_temp_dir().'/codex-auth-'.bin2hex(random_bytes(8));
        $path = $directory.'/auth.json';
        try {
            $store = new CodexAuthFileStore($path);
            $record = new CodexAuthRecord('access', 'refresh', time() + 3600, 'account');
            $store->saveCredentials($record);

            $this->assertSame(0700, fileperms($directory) & 0777);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertEquals($record, $store->loadCredentialsRaw());

            $data = json_decode((string) file_get_contents($path), true, 8, \JSON_THROW_ON_ERROR);
            $data['grok-cli'] = ['access' => 'grok-token'];
            file_put_contents($path, json_encode($data, \JSON_THROW_ON_ERROR));
            $this->assertTrue(chmod($path, 0644));
            $store->saveCredentials(new CodexAuthRecord('new-access', 'new-refresh', time() + 3600, 'account'));

            $saved = json_decode((string) file_get_contents($path), true, 8, \JSON_THROW_ON_ERROR);
            $this->assertSame(['access' => 'grok-token'], $saved['grok-cli']);
            $this->assertSame('new-access', $saved['openai-codex']['access']);
            clearstatcache(true, $path);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertSame([], glob($directory.'/codex-auth-*') ?: []);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testCorruptFileIsNotReplaced(): void
    {
        $directory = sys_get_temp_dir().'/codex-auth-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/auth.json';
        file_put_contents($path, '{bad json');
        try {
            $store = new CodexAuthFileStore($path);
            try {
                $store->saveCredentials(new CodexAuthRecord('access', 'refresh', time() + 3600, 'account'));
                $this->fail('Corrupt credentials file should not be overwritten.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Corrupt credentials file', $e->getMessage());
            }
            $this->assertSame('{bad json', file_get_contents($path));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testRefreshReadsLatestRecordUnderFileLock(): void
    {
        $directory = sys_get_temp_dir().'/codex-auth-'.bin2hex(random_bytes(8));
        $path = $directory.'/auth.json';
        try {
            $store = new CodexAuthFileStore($path);
            $store->saveCredentials(new CodexAuthRecord('first', 'refresh-1', time() + 3600, 'account'));
            $refresher = new class extends CodexTokenRefresher {
                /** @var list<string> */
                public array $seen = [];

                public function refresh(string $refreshToken, string $expectedAccountId): CodexAuthRecord
                {
                    $this->seen[] = $refreshToken;

                    return new CodexAuthRecord('next', 'refresh-'.(\count($this->seen) + 1), time() + 3600, $expectedAccountId);
                }
            };

            $store->refreshWithLock($refresher);
            $store->refreshWithLock($refresher);
            $this->assertSame(['refresh-1', 'refresh-2'], $refresher->seen);
            $this->assertSame('refresh-3', $store->loadCredentialsRaw()?->refresh);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
