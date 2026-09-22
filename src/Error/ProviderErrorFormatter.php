<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Error;

/**
 * Formats provider error payloads into bounded, single-line diagnostics.
 *
 * Provider error bodies vary between bridges (Codex HTTP/WebSocket, Grok,
 * generic OpenAI-compatible), but the useful fields are always
 * code/type/param/message. This is the single place that extracts and bounds
 * them so every bridge produces the same readable "[code/type/param]: message"
 * shape.
 */
final class ProviderErrorFormatter
{
    private const int MAX_FIELD = 64;
    private const int MAX_MESSAGE = 500;

    /**
     * Decode a JSON body and return its structured "error" object.
     *
     * @return array<string, mixed> empty when the body is not JSON or has no error object
     */
    public static function decodeError(string $body): array
    {
        $data = json_decode($body, true);

        return \is_array($data['error'] ?? null) ? $data['error'] : [];
    }

    public static function formatBody(string $body): string
    {
        $formatted = self::format(self::decodeError($body));

        return '' !== $formatted
            ? $formatted
            : mb_substr(trim(preg_replace('/\s+/', ' ', $body) ?? ''), 0, 200);
    }

    /**
     * Format only the structured code/type/param fields as "[code/type/param]".
     *
     * Returns an empty string when none of the fields are present.
     *
     * @param array<string, mixed> $error
     */
    public static function formatFields(array $error): string
    {
        $parts = [];
        foreach (['code', 'type', 'param'] as $key) {
            $value = $error[$key] ?? null;
            if (\is_string($value) && '' !== trim($value)) {
                $parts[] = mb_substr(trim($value), 0, self::MAX_FIELD);
            }
        }

        return [] !== $parts ? \sprintf('[%s]', implode('/', $parts)) : '';
    }

    /**
     * Format decoded provider error data into "[code/type/param]: message".
     *
     * Returns the bare bounded message when only a message exists, and an
     * empty string when the error data has nothing usable.
     *
     * @param array<string, mixed> $error
     */
    public static function format(array $error): string
    {
        $message = '';
        if (\is_string($error['message'] ?? null) && '' !== trim($error['message'])) {
            $message = mb_substr(trim($error['message']), 0, self::MAX_MESSAGE);
        }

        $fields = self::formatFields($error);
        if ('' !== $fields) {
            return '' !== $message ? \sprintf('%s: %s', $fields, $message) : $fields;
        }

        return $message;
    }
}
