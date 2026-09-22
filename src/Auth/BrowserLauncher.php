<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * Cross-platform browser URL launcher.
 *
 * Uses Symfony Process to run the platform's default browser command:
 *  - Linux:    xdg-open
 *  - macOS:    open
 *  - Windows:  cmd /c start
 *
 * Silently degrades when no browser command is available.
 */
final class BrowserLauncher
{
    /**
     * Try to open a URL in the default browser.
     *
     * @return bool True if the browser command was launched successfully
     */
    public static function open(string $url, ?LoggerInterface $logger = null): bool
    {
        $command = self::detectCommand($url);

        if (null === $command) {
            return false;
        }

        try {
            $process = new Process($command);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable $exception) {
            // The printed URL remains available for manual use. Do not log it.
            ($logger ?? new NullLogger())->warning('codex.oauth.browser_launch_failed', [
                'component' => 'codex_oauth',
                'event_type' => 'codex.oauth.browser_launch_failed',
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * @return list<string>|null Shell command with arguments, or null if undetectable
     */
    private static function detectCommand(string $url): ?array
    {
        return match (\PHP_OS_FAMILY) {
            'Linux' => ['xdg-open', $url],
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => null,
        };
    }
}
