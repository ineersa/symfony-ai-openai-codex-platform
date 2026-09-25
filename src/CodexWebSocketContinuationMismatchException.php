<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Cached WebSocket continuation rejected an unexpected history mismatch.
 *
 * This is a local invariant failure: retrying the same prepared request would
 * only hide a replay or state bug. Exception messages stay free of prompts,
 * tool args, keys, and provider IDs.
 */
final class CodexWebSocketContinuationMismatchException extends \LogicException
{
    public function __construct(string $reason)
    {
        parent::__construct(\sprintf(
            'Cached Codex WebSocket continuation rejected (%s); full-history fallback is disabled.',
            $reason,
        ));
    }
}
