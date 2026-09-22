<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Result;

use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * Raw provider result that can be aborted while streaming is in progress.
 *
 * Hosts call abort() when they stop consuming a stream before completion.
 */
interface CancellableRawResultInterface extends RawResultInterface
{
    public function abort(): void;
}
