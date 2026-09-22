<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support;

use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthStorageInterface;

final class InMemoryAuthStorage implements CodexAuthStorageInterface
{
    /** @var array<string, CodexAuthRecord> */
    public array $records = [];

    public function loadCredentialsRaw(string $providerKey): ?CodexAuthRecord
    {
        return $this->records[$providerKey] ?? null;
    }

    public function saveCredentials(string $providerKey, CodexAuthRecord $record): void
    {
        $this->records[$providerKey] = $record;
    }
}
