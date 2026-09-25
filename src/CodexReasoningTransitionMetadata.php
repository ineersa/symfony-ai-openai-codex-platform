<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * History-bound reasoning transition markers for flag-gated Codex models.
 *
 * MESSAGE_KEY is a stable identity for a surviving request segment.
 * KEY holds the effort to emit as configuration_update immediately before
 * that segment in CodexMessageBagNormalizer.
 */
final class CodexReasoningTransitionMetadata
{
    public const string KEY = 'codex_reasoning_effort';

    public const string MESSAGE_KEY = 'codex_reasoning_message_key';

    private function __construct()
    {
    }
}
