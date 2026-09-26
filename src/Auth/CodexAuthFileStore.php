<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Persist Codex credentials under the openai-codex key in a shared JSON file. */
final class CodexAuthFileStore implements CodexAuthRefreshStorageInterface
{
    private readonly LockFactory $lockFactory;

    public function __construct(private readonly string $path, ?LockFactory $lockFactory = null)
    {
        // Supply the same factory as other writers of the file when sharing auth.json.
        $this->lockFactory = $lockFactory ?? new LockFactory(new FlockStore());
    }

    public function loadCredentialsRaw(): ?CodexAuthRecord
    {
        $entry = $this->readAll()[CodexOAuthConfig::PROVIDER_KEY] ?? null;

        return \is_array($entry) ? CodexAuthRecord::fromArray($entry) : null;
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
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create credentials directory at %s.', $directory));
        }

        $temp = $this->path.'.tmp.'.bin2hex(random_bytes(8));
        $stream = @fopen($temp, 'x');
        if (false === $stream) {
            throw new \RuntimeException(\sprintf('Cannot create temporary credentials file at %s.', $this->path));
        }

        try {
            if (!@chmod($temp, 0600)) {
                throw new \RuntimeException('Cannot protect temporary credentials file.');
            }

            $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $length = \strlen($json);
            for ($offset = 0; $offset < $length; $offset += $written) {
                $written = @fwrite($stream, substr($json, $offset));
                if (false === $written || 0 === $written) {
                    throw new \RuntimeException('Cannot write credentials file.');
                }
            }
            if (!@fflush($stream) || !@fclose($stream)) {
                $stream = false;
                throw new \RuntimeException('Cannot flush credentials file.');
            }
            $stream = false;
            if (!@rename($temp, $this->path)) {
                throw new \RuntimeException(\sprintf('Cannot replace credentials file at %s.', $this->path));
            }
        } finally {
            if (false !== $stream) {
                @fclose($stream);
            }
            @unlink($temp);
        }
    }
}
