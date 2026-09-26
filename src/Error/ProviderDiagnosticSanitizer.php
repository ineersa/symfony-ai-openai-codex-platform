<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Error;

/** @internal Provider-controlled diagnostics must not disclose common credential shapes. */
final class ProviderDiagnosticSanitizer
{
    public static function sanitize(string $message, int $length = 500): string
    {
        $message = preg_replace('/\bsk-[A-Za-z0-9_-]+\b/', '<redacted>', $message) ?? $message;
        $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer <redacted>', $message) ?? $message;
        $message = preg_replace('/\b(authorization|api[-_ ]?key|token|secret|password)\s*[:=]\s*["\']?\S+/i', '$1=<redacted>', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return mb_substr(trim($message), 0, $length);
    }
}
