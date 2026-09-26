<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Persist Codex credentials under the openai-codex key in a shared JSON file. */
final class CodexAuthFileStore implements CodexAuthRefreshStorageInterface
{
    private readonly LockFactory $lockFactory;

    public function __construct(
        private readonly string $path,
        ?LockFactory $lockFactory = null,
        private readonly ?CodexTokenRefresher $tokenRefresher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // Supply the same factory as other writers of the file when sharing auth.json.
        $this->lockFactory = $lockFactory ?? new LockFactory(new FlockStore());
    }

    public function loadCredentialsRaw(): ?CodexAuthRecord
    {
        $entry = $this->readAll()[CodexOAuthConfig::PROVIDER_KEY] ?? null;

        return \is_array($entry) ? CodexAuthRecord::fromArray($entry) : null;
    }

    /** Load credentials and refresh an expired record under the shared file lock. */
    public function loadCredentials(): ?CodexAuthRecord
    {
        $lock = $this->lockFactory->createLock('auth.json');
        $lock->acquire(true);

        try {
            $data = $this->readAll();
            $entry = $data[CodexOAuthConfig::PROVIDER_KEY] ?? null;
            if (!\is_array($entry)) {
                return null;
            }

            $record = CodexAuthRecord::fromArray($entry);
            if (!$record->isExpired() || null === $this->tokenRefresher) {
                return $record;
            }

            try {
                $fresh = $this->tokenRefresher->refresh($record->refresh, $record->accountId);
                $data[CodexOAuthConfig::PROVIDER_KEY] = $fresh->toArray();
                $this->writeAll($data);

                return $fresh;
            } catch (\Throwable $e) {
                $this->logger?->warning('Codex token refresh failed for expired record', [
                    'provider_key' => CodexOAuthConfig::PROVIDER_KEY,
                    'component' => 'codex_auth_storage',
                    'event_type' => 'codex_token_refresh_failed',
                ]);

                throw new \RuntimeException('Stored Codex credentials have expired and could not be refreshed. Run bin/console auth:codex to re-authenticate.', previous: $e);
            }
        } finally {
            $lock->release();
        }
    }

    public function saveCredentials(CodexAuthRecord $record): void
    {
        // Lock the whole file, not the provider key, to preserve sibling entries.
        $lock = $this->lockFactory->createLock('auth.json');
        $lock->acquire(true);

        try {
            $data = $this->readAll();
            $data[CodexOAuthConfig::PROVIDER_KEY] = $record->toArray();
            $this->writeAll($data);
        } finally {
            $lock->release();
        }
    }

    public function refreshWithLock(CodexTokenRefresher $refresher): CodexAuthRecord
    {
        $lock = $this->lockFactory->createLock('auth.json');
        $lock->acquire(true);

        try {
            $data = $this->readAll();
            $entry = $data[CodexOAuthConfig::PROVIDER_KEY] ?? null;
            if (!\is_array($entry)) {
                throw new \RuntimeException('No stored Codex credentials found.');
            }

            $record = CodexAuthRecord::fromArray($entry);
            $fresh = $refresher->refresh($record->refresh, $record->accountId);
            $data[CodexOAuthConfig::PROVIDER_KEY] = $fresh->toArray();
            $this->writeAll($data);

            return $fresh;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function readAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $json = @file_get_contents($this->path);
        if (false === $json) {
            throw new \RuntimeException(\sprintf('Cannot read Codex credentials at %s.', $this->path));
        }
        if ('' === trim($json)) {
            return [];
        }

        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('Corrupt credentials file at %s.', $this->path), previous: $e);
        }

        if (!\is_array($data) || array_is_list($data) && [] !== $data) {
            throw new \RuntimeException(\sprintf('Invalid credentials file at %s.', $this->path));
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function writeAll(array $data): void
    {
        $filesystem = new Filesystem();
        $directory = \dirname($this->path);
        $filesystem->mkdir($directory, 0700);
        $temp = $filesystem->tempnam($directory, 'codex-auth-');

        try {
            // dumpFile copies the target's mode onto its own temp file before
            // publishing. Give it a private target, including on first write.
            $filesystem->chmod($temp, 0600);
            $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $filesystem->dumpFile($temp, $json);
            $filesystem->rename($temp, $this->path, true);
        } finally {
            $filesystem->remove($temp);
        }
    }
}
