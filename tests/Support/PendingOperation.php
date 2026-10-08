<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Revolt\EventLoop;

/** A readiness barrier and an owned safety cap for a pending transport call. */
final class PendingOperation
{
    /** @var DeferredFuture<null> */
    public readonly DeferredFuture $ready;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $release;

    private readonly string $safety;

    public bool $finished = false;

    public function __construct()
    {
        $this->ready = new DeferredFuture();
        $this->release = new DeferredFuture();
        $this->safety = EventLoop::delay(2.0, function (): void {
            $this->close();
        });
    }

    public function wait(?Cancellation $cancellation = null): void
    {
        $this->ready->complete();
        try {
            $this->release->getFuture()->await($cancellation);
        } finally {
            $this->finished = true;
        }
    }

    public function close(): void
    {
        if (!$this->release->isComplete()) {
            $this->release->complete();
        }
        EventLoop::cancel($this->safety);
    }
}
