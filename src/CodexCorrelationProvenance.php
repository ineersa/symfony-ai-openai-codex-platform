<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Whether the provider-facing Codex correlation ID was supplied or generated.
 */
enum CodexCorrelationProvenance
{
    case Generated;

    case ExplicitPromptCacheKey;
}
