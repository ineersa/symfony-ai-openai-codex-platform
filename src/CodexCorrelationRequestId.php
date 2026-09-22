<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\Component\Uid\UuidV7;

/**
 * Resolves the provider-facing Codex prompt cache and request correlation ID.
 */
final class CodexCorrelationRequestId
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $payload
     */
    public static function resolve(array $options, array $payload): CodexCorrelationResolution
    {
        $explicit = $payload['prompt_cache_key'] ?? $options['prompt_cache_key'] ?? null;
        if (\is_string($explicit) && '' !== $explicit) {
            return new CodexCorrelationResolution($explicit, $options, CodexCorrelationProvenance::ExplicitPromptCacheKey);
        }

        $generated = UuidV7::v7()->toRfc4122();
        $options['prompt_cache_key'] = $generated;

        return new CodexCorrelationResolution($generated, $options, CodexCorrelationProvenance::Generated);
    }
}
