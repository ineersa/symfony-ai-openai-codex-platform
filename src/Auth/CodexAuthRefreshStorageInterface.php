<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

/** Credential stores that serialize refresh-token rotation with other writers. */
interface CodexAuthRefreshStorageInterface extends CodexAuthStorageInterface
{
    public function refreshWithLock(CodexTokenRefresher $refresher): CodexAuthRecord;
}
