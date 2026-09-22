<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support;

use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\LocalCallbackServer;

final class CallbackServer extends LocalCallbackServer
{
    public ?string $state = null;
    public ?int $port = null;
    public ?float $timeout = null;

    public function __construct(private readonly ?string $code = 'authorization-code')
    {
    }

    public function waitForCallback(string $expectedState, float $timeoutSeconds = 300.0, int $port = 1455, ?callable $afterListen = null, string $callbackPath = '/auth/callback'): ?array
    {
        $this->state = $expectedState;
        $this->port = $port;
        $this->timeout = $timeoutSeconds;
        if (null !== $afterListen) {
            $afterListen();
        }

        return null === $this->code ? null : ['code' => $this->code];
    }
}
