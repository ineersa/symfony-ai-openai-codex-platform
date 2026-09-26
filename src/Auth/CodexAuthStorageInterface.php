<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

/** Host-owned credential persistence. Loading must not trigger automatic refresh. */
interface CodexAuthStorageInterface
{
    public function loadCredentialsRaw(): ?CodexAuthRecord;

    public function saveCredentials(CodexAuthRecord $record): void;
}
