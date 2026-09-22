<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Deterministic structural comparison for Codex continuation eligibility.
 */
final class CodexWebSocketContinuationComparator
{
    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function requestBodiesMatchExceptInput(array $a, array $b): bool
    {
        return self::encode(self::bodyWithoutContinuationFields($a))
            === self::encode(self::bodyWithoutContinuationFields($b));
    }

    /**
     * @param list<mixed> $a
     * @param list<mixed> $b
     */
    public static function responseInputsEqual(array $a, array $b): bool
    {
        return self::encode($a) === self::encode($b);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function bodyWithoutContinuationFields(array $body): array
    {
        unset($body['input'], $body['previous_response_id'], $body['prompt_cache_key']);

        return $body;
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
