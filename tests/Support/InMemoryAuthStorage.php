<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support;

use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthStorageInterface;

final class InMemoryAuthStorage implements CodexAuthStorageInterface
{
    public ?CodexAuthRecord $record = null;

    public function loadCredentialsRaw(): ?CodexAuthRecord
    {
        return $this->record;
    }

    public function saveCredentials(CodexAuthRecord $record): void
    {
        $this->record = $record;
    }
}
