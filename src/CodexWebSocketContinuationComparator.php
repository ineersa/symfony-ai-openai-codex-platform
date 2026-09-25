<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Deterministic structural comparison for Codex continuation eligibility.
 */
final class CodexWebSocketContinuationComparator
{
    /** @var array<string, string> */
    private const array KNOWN_ITEM_TYPES = [
        'message' => 'message',
        'function_call' => 'function_call',
        'function_call_output' => 'function_call_output',
        'reasoning' => 'reasoning',
        'configuration_update' => 'configuration_update',
        'custom_tool_call' => 'custom_tool_call',
        'custom_tool_call_output' => 'custom_tool_call_output',
        'web_search_call' => 'web_search_call',
        'file_search_call' => 'file_search_call',
        'computer_call' => 'computer_call',
        'computer_call_output' => 'computer_call_output',
        'image_generation_call' => 'image_generation_call',
        'code_interpreter_call' => 'code_interpreter_call',
        'local_shell_call' => 'local_shell_call',
        'mcp_call' => 'mcp_call',
        'mcp_list_tools' => 'mcp_list_tools',
        'mcp_approval_request' => 'mcp_approval_request',
    ];

    /** @var array<string, string> */
    private const array KNOWN_ITEM_ROLES = [
        'user' => 'role:user',
        'assistant' => 'role:assistant',
        'system' => 'role:system',
        'developer' => 'role:developer',
        'tool' => 'role:tool',
    ];

    /**
     * Allowlisted object keys that may appear in mismatch field paths.
     *
     * Dynamic provider keys, IDs, and free-form maps stay out of logs.
     *
     * @var array<string, true>
     */
    private const array ALLOWLISTED_FIELD_KEYS = [
        'type' => true,
        'role' => true,
        'status' => true,
        'phase' => true,
        'name' => true,
        'call_id' => true,
        'content' => true,
        'text' => true,
        'arguments' => true,
        'output' => true,
        'summary' => true,
        'encrypted_content' => true,
        'reasoning' => true,
        'effort' => true,
        'id' => true,
    ];

    /** Maximum nested object/list depth explored for mismatch field paths. */
    private const int MAX_MISMATCH_DEPTH = 4;

    /** Maximum characters retained in a logged mismatch field path. */
    private const int MAX_MISMATCH_PATH_LENGTH = 64;

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
        return self::encode(array_map(self::comparableInput(...), $a))
            === self::encode(array_map(self::comparableInput(...), $b));
    }

    /**
     * Locate the first structural mismatch between two input prefixes.
     *
     * Returns kinds, an index, and a privacy-safe structural field path —
     * never item contents, hashes of secrets, or dynamic provider keys.
     *
     * @param list<mixed> $left
     * @param list<mixed> $right
     *
     * @return array{
     *     first_mismatch_index: ?int,
     *     left_item_kind: ?string,
     *     right_item_kind: ?string,
     *     prefix_normalized_equal: bool,
     *     mismatch_field_path: ?string,
     *     mismatch_relation: ?string,
     *     left_value_kind: ?string,
     *     right_value_kind: ?string
     * }
     */
    public static function describePrefixMismatch(array $left, array $right): array
    {
        $leftComparable = array_map(self::comparableInput(...), $left);
        $rightComparable = array_map(self::comparableInput(...), $right);
        if (self::encode($leftComparable) === self::encode($rightComparable)) {
            return [
                'first_mismatch_index' => null,
                'left_item_kind' => null,
                'right_item_kind' => null,
                'prefix_normalized_equal' => true,
                'mismatch_field_path' => null,
                'mismatch_relation' => null,
                'left_value_kind' => null,
                'right_value_kind' => null,
            ];
        }

        $limit = min(\count($leftComparable), \count($rightComparable));
        for ($i = 0; $i < $limit; ++$i) {
            if (self::encode($leftComparable[$i]) !== self::encode($rightComparable[$i])) {
                $field = self::describeComparableValueMismatch($leftComparable[$i], $rightComparable[$i]);

                return [
                    'first_mismatch_index' => $i,
                    'left_item_kind' => self::itemKind($left[$i] ?? null),
                    'right_item_kind' => self::itemKind($right[$i] ?? null),
                    'prefix_normalized_equal' => false,
                    'mismatch_field_path' => $field['mismatch_field_path'],
                    'mismatch_relation' => $field['mismatch_relation'],
                    'left_value_kind' => $field['left_value_kind'],
                    'right_value_kind' => $field['right_value_kind'],
                ];
            }
        }

        return [
            'first_mismatch_index' => $limit,
            'left_item_kind' => \count($left) > $limit ? self::itemKind($left[$limit] ?? null) : null,
            'right_item_kind' => \count($right) > $limit ? self::itemKind($right[$limit] ?? null) : null,
            'prefix_normalized_equal' => false,
            'mismatch_field_path' => null,
            'mismatch_relation' => null,
            'left_value_kind' => null,
            'right_value_kind' => null,
        ];
    }

    /**
     * Compare provider output with the history emitted by CodexContract,
     * excluding fields its normalizers omit. Never change actual request items.
     */
    private static function comparableInput(mixed $item): mixed
    {
        if (!\is_array($item)) {
            return $item;
        }

        if ('message' === ($item['type'] ?? null) && 'assistant' === ($item['role'] ?? null)) {
            unset($item['id'], $item['status'], $item['phase']);
            if (\is_array($item['content'] ?? null)) {
                foreach ($item['content'] as &$part) {
                    if (\is_array($part) && 'output_text' === ($part['type'] ?? null)) {
                        unset($part['annotations'], $part['logprobs']);
                    }
                }
                unset($part);
            }
        } elseif ('function_call' === ($item['type'] ?? null)) {
            unset($item['status']);
            // ToolCall conversion parses arguments and the contract encodes
            // them again. Whitespace/escaping are not argument changes.
            if (\is_string($item['arguments'] ?? null) && json_validate($item['arguments'])) {
                $item['arguments'] = json_decode($item['arguments'], flags: \JSON_THROW_ON_ERROR);
            }
        }

        return $item;
    }

    /**
     * @return array{
     *     mismatch_field_path: ?string,
     *     mismatch_relation: ?string,
     *     left_value_kind: ?string,
     *     right_value_kind: ?string
     * }
     */
    private static function describeComparableValueMismatch(mixed $left, mixed $right, string $path = '', int $depth = 0): array
    {
        if (self::encode($left) === self::encode($right)) {
            return self::emptyFieldMismatch();
        }

        if ($depth >= self::MAX_MISMATCH_DEPTH) {
            return self::boundedAncestorMismatch($path, $left, $right);
        }

        $leftIsArray = \is_array($left);
        $rightIsArray = \is_array($right);
        if (!$leftIsArray || !$rightIsArray) {
            return self::scalarFieldMismatch($path, $left, $right);
        }

        $leftList = array_is_list($left);
        $rightList = array_is_list($right);
        if ($leftList !== $rightList) {
            return self::boundedAncestorMismatch($path, $left, $right);
        }

        if ($leftList) {
            $limit = min(\count($left), \count($right));
            for ($i = 0; $i < $limit; ++$i) {
                if (self::encode($left[$i]) !== self::encode($right[$i])) {
                    // Keep list indexes out of logged paths; continue under the
                    // current allowlisted ancestor with a depth budget.
                    return self::describeComparableValueMismatch($left[$i], $right[$i], $path, $depth + 1);
                }
            }

            return self::boundedAncestorMismatch($path, $left, $right);
        }

        $leftKeys = array_keys($left);
        $rightKeys = array_keys($right);
        sort($leftKeys);
        sort($rightKeys);

        foreach (array_values(array_unique([...$leftKeys, ...$rightKeys])) as $key) {
            $leftHas = \array_key_exists($key, $left);
            $rightHas = \array_key_exists($key, $right);
            $childPath = self::appendPath($path, $key);
            if (null === $childPath) {
                // Dynamic/untrusted keys never become log paths. Fall back to
                // the nearest allowlisted ancestor without exposing the key.
                return self::boundedAncestorMismatch($path, $left, $right);
            }

            if ($leftHas !== $rightHas) {
                return [
                    'mismatch_field_path' => $childPath,
                    'mismatch_relation' => $leftHas ? 'absent_right' : 'absent_left',
                    'left_value_kind' => $leftHas ? self::valueKind($left[$key]) : 'absent',
                    'right_value_kind' => $rightHas ? self::valueKind($right[$key]) : 'absent',
                ];
            }

            if (self::encode($left[$key]) !== self::encode($right[$key])) {
                return self::describeComparableValueMismatch($left[$key], $right[$key], $childPath, $depth + 1);
            }
        }

        return self::boundedAncestorMismatch($path, $left, $right);
    }

    /**
     * @return array{
     *     mismatch_field_path: ?string,
     *     mismatch_relation: ?string,
     *     left_value_kind: ?string,
     *     right_value_kind: ?string
     * }
     */
    private static function scalarFieldMismatch(string $path, mixed $left, mixed $right): array
    {
        return [
            'mismatch_field_path' => self::boundedPath($path),
            'mismatch_relation' => 'different',
            'left_value_kind' => self::valueKind($left),
            'right_value_kind' => self::valueKind($right),
        ];
    }

    /**
     * @return array{
     *     mismatch_field_path: null,
     *     mismatch_relation: null,
     *     left_value_kind: null,
     *     right_value_kind: null
     * }
     */
    private static function emptyFieldMismatch(): array
    {
        return [
            'mismatch_field_path' => null,
            'mismatch_relation' => null,
            'left_value_kind' => null,
            'right_value_kind' => null,
        ];
    }

    /**
     * @return array{
     *     mismatch_field_path: ?string,
     *     mismatch_relation: ?string,
     *     left_value_kind: ?string,
     *     right_value_kind: ?string
     * }
     */
    private static function boundedAncestorMismatch(string $path, mixed $left, mixed $right): array
    {
        return [
            'mismatch_field_path' => self::boundedPath($path),
            'mismatch_relation' => 'different',
            'left_value_kind' => self::valueKind($left),
            'right_value_kind' => self::valueKind($right),
        ];
    }

    private static function appendPath(string $path, mixed $key): ?string
    {
        if (!\is_string($key) || !isset(self::ALLOWLISTED_FIELD_KEYS[$key])) {
            return null;
        }

        $candidate = '' === $path ? $key : $path.'.'.$key;
        if (\strlen($candidate) > self::MAX_MISMATCH_PATH_LENGTH) {
            return null;
        }

        return $candidate;
    }

    private static function boundedPath(string $path): string
    {
        if ('' === $path) {
            return '.';
        }

        if (\strlen($path) <= self::MAX_MISMATCH_PATH_LENGTH) {
            return $path;
        }

        return '.';
    }

    private static function valueKind(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return 'bool';
        }
        if (\is_int($value) || \is_float($value)) {
            return 'number';
        }
        if (\is_string($value)) {
            return 'string';
        }
        if (\is_array($value)) {
            return array_is_list($value) ? 'list' : 'object';
        }

        return 'other';
    }

    private static function itemKind(mixed $item): ?string
    {
        if (!\is_array($item)) {
            return null;
        }

        $type = $item['type'] ?? null;
        if (\is_string($type) && '' !== $type) {
            return self::KNOWN_ITEM_TYPES[$type] ?? 'other';
        }

        $role = $item['role'] ?? null;
        if (\is_string($role) && '' !== $role) {
            return self::KNOWN_ITEM_ROLES[$role] ?? 'other';
        }

        return 'other';
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
        return json_encode(self::sortObjectKeys($value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private static function sortObjectKeys(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map(self::sortObjectKeys(...), $properties);
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortObjectKeys(...), $value);
    }
}
