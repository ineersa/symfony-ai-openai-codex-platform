<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\run;

#[AsTask(description: 'Run deterministic package tests')]
function test(): void
{
    run(['php', 'vendor/bin/phpunit', '--stop-on-error', '--stop-on-failure', '--fail-on-all-issues', '--display-all-issues', '--log-junit', 'var/reports/junit.xml'], context: context()->withTimeout(210)->withTty(false));
}

#[AsTask(description: 'Analyze package source and tests')]
function phpstan(): void
{
    run(['php', 'vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=raw', '--memory-limit=512M'], context: context()->withTimeout(210)->withTty(false));
}

#[AsTask(name: 'cs-check', description: 'Check coding style')]
function cs_check(): void
{
    run(['php', 'vendor/bin/php-cs-fixer', 'fix', '--dry-run', '--diff'], context: context()->withTimeout(210)->withTty(false));
}

#[AsTask(name: 'cs-fix', description: 'Fix coding style')]
function cs_fix(): void
{
    run(['php', 'vendor/bin/php-cs-fixer', 'fix'], context: context()->withTimeout(210)->withTty(false));
}

#[AsTask(name: 'composer-validate', description: 'Validate Composer metadata and lock file')]
function composer_validate(): void
{
    run(['composer', 'validate', '--strict'], context: context()->withTimeout(210)->withTty(false));
}
