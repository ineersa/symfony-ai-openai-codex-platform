<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Privacy-safe continuation outcome for structured WebSocket logs.
 *
 * Never carries raw prompt_cache_key, prompts, provider output, or tool args.
 */
final readonly class CodexWebSocketContinuationDecision
{
    public const string REASON_DELTA = 'delta';
    public const string REASON_DIVERGENT_BODY = 'divergent_body';
    public const string REASON_PREFIX_SHORTER = 'prefix_shorter';
    public const string REASON_PREFIX_MISMATCH = 'prefix_mismatch';
    public const string REASON_INVALID_INPUT = 'invalid_input';

    /**
     * @param array{previous_response_id: string, input: list<array<string, mixed>>}|null $delta
     */
    public function __construct(
        public string $reason,
        public ?array $delta,
        public bool $promptCacheKeyPresent,
        public ?string $promptCacheKeyFp,
        public int $promptCacheKeyLength,
        public bool $promptCacheKeyChanged,
        public int $baselineInputCount,
        public int $currentInputCount,
        public ?int $deltaInputCount,
        public ?int $firstMismatchIndex,
        public ?string $leftItemKind,
        public ?string $rightItemKind,
        public bool $prefixNormalizedEqual,
        public ?string $mismatchFieldPath = null,
        public ?string $mismatchRelation = null,
        public ?string $leftValueKind = null,
        public ?string $rightValueKind = null,
        public int $lastRequestInputCount = 0,
        public int $lastResponseItemCount = 0,
        public ?bool $mismatchInResponseItems = null,
        public ?int $mismatchResponseItemOffset = null,
        public ?bool $mismatchIdsEqual = null,
        public ?int $mismatchEncryptedContentLeftLen = null,
        public ?int $mismatchEncryptedContentRightLen = null,
        public int $pendingFunctionCallCount = 0,
        public int $pendingOutputsInPrefixCount = 0,
        public int $pendingOutputsInDeltaCount = 0,
        public int $pendingOutputsMissingCount = 0,
    ) {
    }

    /**
     * @param array{prompt_cache_key_present: bool, prompt_cache_key_fp: ?string, prompt_cache_key_length: int} $keyContext
     * @param array{
     *     first_mismatch_index?: ?int,
     *     left_item_kind?: ?string,
     *     right_item_kind?: ?string,
     *     prefix_normalized_equal?: bool,
     *     mismatch_field_path?: ?string,
     *     mismatch_relation?: ?string,
     *     left_value_kind?: ?string,
     *     right_value_kind?: ?string,
     *     mismatch_in_response_items?: ?bool,
     *     mismatch_response_item_offset?: ?int,
     *     mismatch_ids_equal?: ?bool,
     *     mismatch_encrypted_content_left_len?: ?int,
     *     mismatch_encrypted_content_right_len?: ?int
     * }|null $mismatch
     * @param array{
     *     last_request_input_count: int,
     *     last_response_item_count: int,
     *     pending_function_call_count: int,
     *     pending_outputs_in_prefix_count: int,
     *     pending_outputs_in_delta_count: int,
     *     pending_outputs_missing_count: int
     * } $structure
     */
    public static function reject(
        string $reason,
        array $keyContext,
        bool $promptCacheKeyChanged,
        int $baselineInputCount,
        int $currentInputCount,
        ?array $mismatch = null,
        array $structure = [
            'last_request_input_count' => 0,
            'last_response_item_count' => 0,
            'pending_function_call_count' => 0,
            'pending_outputs_in_prefix_count' => 0,
            'pending_outputs_in_delta_count' => 0,
            'pending_outputs_missing_count' => 0,
        ],
    ): self {
        return new self(
            reason: $reason,
            delta: null,
            promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
            promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
            promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
            promptCacheKeyChanged: $promptCacheKeyChanged,
            baselineInputCount: $baselineInputCount,
            currentInputCount: $currentInputCount,
            deltaInputCount: null,
            firstMismatchIndex: $mismatch['first_mismatch_index'] ?? null,
            leftItemKind: $mismatch['left_item_kind'] ?? null,
            rightItemKind: $mismatch['right_item_kind'] ?? null,
            prefixNormalizedEqual: $mismatch['prefix_normalized_equal'] ?? false,
            mismatchFieldPath: $mismatch['mismatch_field_path'] ?? null,
            mismatchRelation: $mismatch['mismatch_relation'] ?? null,
            leftValueKind: $mismatch['left_value_kind'] ?? null,
            rightValueKind: $mismatch['right_value_kind'] ?? null,
            lastRequestInputCount: $structure['last_request_input_count'],
            lastResponseItemCount: $structure['last_response_item_count'],
            mismatchInResponseItems: $mismatch['mismatch_in_response_items'] ?? null,
            mismatchResponseItemOffset: $mismatch['mismatch_response_item_offset'] ?? null,
            mismatchIdsEqual: $mismatch['mismatch_ids_equal'] ?? null,
            mismatchEncryptedContentLeftLen: $mismatch['mismatch_encrypted_content_left_len'] ?? null,
            mismatchEncryptedContentRightLen: $mismatch['mismatch_encrypted_content_right_len'] ?? null,
            pendingFunctionCallCount: $structure['pending_function_call_count'],
            pendingOutputsInPrefixCount: $structure['pending_outputs_in_prefix_count'],
            pendingOutputsInDeltaCount: $structure['pending_outputs_in_delta_count'],
            pendingOutputsMissingCount: $structure['pending_outputs_missing_count'],
        );
    }

    /**
     * @param array{previous_response_id: string, input: list<array<string, mixed>>}                            $delta
     * @param array{prompt_cache_key_present: bool, prompt_cache_key_fp: ?string, prompt_cache_key_length: int} $keyContext
     * @param array{
     *     last_request_input_count: int,
     *     last_response_item_count: int,
     *     pending_function_call_count: int,
     *     pending_outputs_in_prefix_count: int,
     *     pending_outputs_in_delta_count: int,
     *     pending_outputs_missing_count: int
     * } $structure
     */
    public static function accept(
        array $delta,
        array $keyContext,
        bool $promptCacheKeyChanged,
        int $baselineInputCount,
        int $currentInputCount,
        int $deltaInputCount,
        array $structure = [
            'last_request_input_count' => 0,
            'last_response_item_count' => 0,
            'pending_function_call_count' => 0,
            'pending_outputs_in_prefix_count' => 0,
            'pending_outputs_in_delta_count' => 0,
            'pending_outputs_missing_count' => 0,
        ],
    ): self {
        return new self(
            reason: self::REASON_DELTA,
            delta: $delta,
            promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
            promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
            promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
            promptCacheKeyChanged: $promptCacheKeyChanged,
            baselineInputCount: $baselineInputCount,
            currentInputCount: $currentInputCount,
            deltaInputCount: $deltaInputCount,
            firstMismatchIndex: null,
            leftItemKind: null,
            rightItemKind: null,
            prefixNormalizedEqual: true,
            mismatchFieldPath: null,
            mismatchRelation: null,
            leftValueKind: null,
            rightValueKind: null,
            lastRequestInputCount: $structure['last_request_input_count'],
            lastResponseItemCount: $structure['last_response_item_count'],
            mismatchInResponseItems: null,
            mismatchResponseItemOffset: null,
            mismatchIdsEqual: null,
            mismatchEncryptedContentLeftLen: null,
            mismatchEncryptedContentRightLen: null,
            pendingFunctionCallCount: $structure['pending_function_call_count'],
            pendingOutputsInPrefixCount: $structure['pending_outputs_in_prefix_count'],
            pendingOutputsInDeltaCount: $structure['pending_outputs_in_delta_count'],
            pendingOutputsMissingCount: $structure['pending_outputs_missing_count'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'reason' => $this->reason,
            'prompt_cache_key_present' => $this->promptCacheKeyPresent,
            'prompt_cache_key_fp' => $this->promptCacheKeyFp,
            'prompt_cache_key_length' => $this->promptCacheKeyLength,
            'prompt_cache_key_changed' => $this->promptCacheKeyChanged,
            'baseline_input_count' => $this->baselineInputCount,
            'current_input_count' => $this->currentInputCount,
            'delta_input_count' => $this->deltaInputCount,
            'first_mismatch_index' => $this->firstMismatchIndex,
            'left_item_kind' => $this->leftItemKind,
            'right_item_kind' => $this->rightItemKind,
            'prefix_normalized_equal' => $this->prefixNormalizedEqual,
            'mismatch_field_path' => $this->mismatchFieldPath,
            'mismatch_relation' => $this->mismatchRelation,
            'left_value_kind' => $this->leftValueKind,
            'right_value_kind' => $this->rightValueKind,
            'last_request_input_count' => $this->lastRequestInputCount,
            'last_response_item_count' => $this->lastResponseItemCount,
            'mismatch_in_response_items' => $this->mismatchInResponseItems,
            'mismatch_response_item_offset' => $this->mismatchResponseItemOffset,
            'mismatch_ids_equal' => $this->mismatchIdsEqual,
            'mismatch_encrypted_content_left_len' => $this->mismatchEncryptedContentLeftLen,
            'mismatch_encrypted_content_right_len' => $this->mismatchEncryptedContentRightLen,
            'pending_function_call_count' => $this->pendingFunctionCallCount,
            'pending_outputs_in_prefix_count' => $this->pendingOutputsInPrefixCount,
            'pending_outputs_in_delta_count' => $this->pendingOutputsInDeltaCount,
            'pending_outputs_missing_count' => $this->pendingOutputsMissingCount,
        ];
    }

    /**
     * Bounded fingerprint of a canonical UUIDv7 prompt_cache_key only.
     *
     * Non-UUIDv7 values stay marked present/length-only so low-entropy keys are
     * never self-HMAC fingerprinted.
     *
     * @param array<string, mixed> $body
     *
     * @return array{prompt_cache_key_present: bool, prompt_cache_key_fp: ?string, prompt_cache_key_length: int}
     */
    public static function promptCacheKeyContext(array $body): array
    {
        $key = $body['prompt_cache_key'] ?? null;
        if (!\is_string($key) || '' === $key) {
            return [
                'prompt_cache_key_present' => false,
                'prompt_cache_key_fp' => null,
                'prompt_cache_key_length' => 0,
            ];
        }

        $fingerprint = null;
        if (Uuid::isValid($key) && Uuid::fromString($key) instanceof UuidV7) {
            $fingerprint = substr(hash_hmac('sha256', $key, $key), 0, 16);
        }

        return [
            'prompt_cache_key_present' => true,
            'prompt_cache_key_fp' => $fingerprint,
            'prompt_cache_key_length' => \strlen($key),
        ];
    }

    /**
     * @param array<string, mixed> $currentBody
     * @param array<string, mixed> $lastBody
     */
    public static function promptCacheKeyChanged(array $currentBody, array $lastBody): bool
    {
        $current = $currentBody['prompt_cache_key'] ?? null;
        $last = $lastBody['prompt_cache_key'] ?? null;
        $currentKey = \is_string($current) && '' !== $current ? $current : null;
        $lastKey = \is_string($last) && '' !== $last ? $last : null;

        return $currentKey !== $lastKey;
    }
}
