<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Support;

/**
 * Separates Responses function_call call_id from optional item id.
 *
 * Symfony AI stores one ToolCall id. Native Codex results may encode both
 * halves as "{call_id}|{item_id}". Foreign chat-completions ids are bare
 * call ids and must not be copied into the Responses item id field.
 */
final class CodexResponsesToolCallId
{
    /**
     * @return array{call_id: string, item_id: ?string}
     */
    public static function split(string $storedId): array
    {
        if (!str_contains($storedId, '|')) {
            return [
                'call_id' => $storedId,
                // Existing Codex events stored only the native item id.
                'item_id' => self::isNativeItemId($storedId) ? $storedId : null,
            ];
        }

        [$callId, $itemId] = explode('|', $storedId, 2);

        return [
            'call_id' => '' !== $callId ? $callId : $storedId,
            'item_id' => '' !== $itemId ? $itemId : null,
        ];
    }

    public static function isNativeItemId(?string $itemId): bool
    {
        return \is_string($itemId) && str_starts_with($itemId, 'fc_');
    }
}
