<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Register this command in the host application with its credential storage adapter. */
final class CodexAuthCommand extends Command
{
    public function __construct(
        private readonly CodexOAuthService $oauthService,
        private readonly CodexOAuthConfig $config = new CodexOAuthConfig(),
    ) {
        parent::__construct($config->commandName);
    }

    protected function configure(): void
    {
        $this->setDescription('Authenticate with '.$this->config->displayName.' subscription (OAuth PKCE)')
            ->addOption('no-browser', null, InputOption::VALUE_NONE, 'Print the authorization URL without opening a browser')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Callback timeout in seconds', CodexOAuthConfig::DEFAULT_TIMEOUT)
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Local callback TCP port', CodexOAuthConfig::DEFAULT_PORT)
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Refresh stored credentials instead of logging in')
            ->addOption('auth-profile', null, InputOption::VALUE_REQUIRED, 'Account profile name, for example work or personal');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $profile = $input->getOption('auth-profile');
        try {
            $providerKey = CodexOAuthConfig::providerKeyForProfile($profile);
            $port = $this->integerOption($input, 'port');
            $timeout = $this->integerOption($input, 'timeout');
            $refresh = $input->getOption('refresh');
            $record = $refresh
                ? $this->oauthService->refreshCredentials($providerKey)
                : $this->oauthService->login(
                    $io,
                    noBrowser: $input->getOption('no-browser'),
                    timeout: $timeout,
                    port: $port,
                    providerKey: $providerKey,
                );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $profileLabel = null !== $profile && '' !== trim($profile) ? ' (profile: '.$profile.')' : '';
        $io->success(\sprintf(
            '%s%s%s. Token expires at %s.',
            $this->config->displayName,
            $refresh ? ' credentials refreshed' : ' authentication successful',
            $profileLabel,
            date('Y-m-d H:i:s T', $record->expires),
        ));

        return self::SUCCESS;
    }

    private function integerOption(InputInterface $input, string $name): int
    {
        $value = filter_var($input->getOption($name), \FILTER_VALIDATE_INT);
        if (false === $value) {
            throw new \InvalidArgumentException(\sprintf('--%s must be an integer.', $name));
        }

        return $value;
    }
}
