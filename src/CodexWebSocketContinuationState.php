<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Committed continuation baseline for a single cached WebSocket connection.
 */
final class CodexWebSocketContinuationState
{
    /**
     * @param array<string, mixed>       $lastRequestBody
     * @param list<array<string, mixed>> $lastResponseItems
     */
    public function __construct(
        private array $lastRequestBody,
        private string $lastResponseId,
        private array $lastResponseItems,
    ) {
        if ('' === $this->lastResponseId) {
            throw new \InvalidArgumentException('Codex continuation response id must not be empty.');
        }
    }

    /**
     * A changed tool catalog is a known request boundary, but must not hide a
     * simultaneous change to previously sent history or other request fields.
     *
     * @param array<string, mixed> $currentRequestBody
     */
    public function requiresFreshChainForTools(array $currentRequestBody): bool
    {
        if (CodexWebSocketContinuationComparator::requestBodiesMatchExceptInput($currentRequestBody, $this->lastRequestBody)) {
            return false;
        }

        $withPreviousTools = $currentRequestBody;
        if (\array_key_exists('tools', $this->lastRequestBody)) {
            $withPreviousTools['tools'] = $this->lastRequestBody['tools'];
        } else {
            unset($withPreviousTools['tools']);
        }

        return null !== $this->decide($withPreviousTools)->delta;
    }

    /**
     * Classify continuation eligibility with privacy-safe structural diagnostics.
     *
     * @param array<string, mixed> $currentRequestBody
     */
    public function decide(array $currentRequestBody): CodexWebSocketContinuationDecision
    {
        $keyContext = CodexWebSocketContinuationDecision::promptCacheKeyContext($currentRequestBody);
        $keyChanged = CodexWebSocketContinuationDecision::promptCacheKeyChanged($currentRequestBody, $this->lastRequestBody);

        $lastRequestInput = $this->lastRequestBody['input'] ?? [];
        if (!\is_array($lastRequestInput)) {
            $lastRequestInput = [];
        }

        /** @var list<array<string, mixed>> $baseline */
        $baseline = array_merge($lastRequestInput, $this->lastResponseItems);
        $baselineCount = \count($baseline);
        $currentCount = self::inputCount($currentRequestBody);
        $structure = self::emptyStructure(\count($lastRequestInput), \count($this->lastResponseItems));

        if (!CodexWebSocketContinuationComparator::requestBodiesMatchExceptInput($currentRequestBody, $this->lastRequestBody)) {
            return CodexWebSocketContinuationDecision::reject(
                CodexWebSocketContinuationDecision::REASON_DIVERGENT_BODY,
                $keyContext,
                $keyChanged,
                $baselineCount,
                $currentCount,
                null,
                $structure,
            );
        }

        $currentInput = $currentRequestBody['input'] ?? [];
        if (!\is_array($currentInput)) {
            return CodexWebSocketContinuationDecision::reject(
                CodexWebSocketContinuationDecision::REASON_INVALID_INPUT,
                $keyContext,
                $keyChanged,
                $baselineCount,
                0,
                null,
                $structure,
            );
        }

        $currentCount = \count($currentInput);
        $structure = self::structureForDecision(
            $lastRequestInput,
            $this->lastResponseItems,
            $currentInput,
            $baselineCount,
        );

        if ($currentCount < $baselineCount) {
            return CodexWebSocketContinuationDecision::reject(
                CodexWebSocketContinuationDecision::REASON_PREFIX_SHORTER,
                $keyContext,
                $keyChanged,
                $baselineCount,
                $currentCount,
                null,
                $structure,
            );
        }

        $prefix = \array_slice($currentInput, 0, $baselineCount);
        if (!CodexWebSocketContinuationComparator::responseInputsEqual($prefix, $baseline)) {
            $mismatch = CodexWebSocketContinuationComparator::describePrefixMismatch($prefix, $baseline);
            $mismatch = self::enrichMismatchDiagnostics(
                $mismatch,
                $prefix,
                $baseline,
                \count($lastRequestInput),
            );

            return CodexWebSocketContinuationDecision::reject(
                CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH,
                $keyContext,
                $keyChanged,
                $baselineCount,
                $currentCount,
                $mismatch,
                $structure,
            );
        }

        $deltaInput = \array_slice($currentInput, $baselineCount);

        return CodexWebSocketContinuationDecision::accept(
            [
                'previous_response_id' => $this->lastResponseId,
                'input' => $deltaInput,
            ],
            $keyContext,
            $keyChanged,
            $baselineCount,
            $currentCount,
            \count($deltaInput),
            $structure,
        );
    }

    /**
     * @param array<string, mixed>       $fullRequestBody
     * @param list<array<string, mixed>> $responseItems
     */
    public static function fromSuccessfulResponse(
        array $fullRequestBody,
        string $responseId,
        array $responseItems,
    ): self {
        $canonicalItems = [];
        foreach ($responseItems as $item) {
            if (\is_array($item)) {
                $canonicalItems[] = $item;
            }
        }

        return new self($fullRequestBody, $responseId, $canonicalItems);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function inputCount(array $body): int
    {
        $input = $body['input'] ?? null;

        return \is_array($input) ? \count($input) : 0;
    }

    /**
     * @return array{
     *     last_request_input_count: int,
     *     last_response_item_count: int,
     *     pending_function_call_count: int,
     *     pending_outputs_in_prefix_count: int,
     *     pending_outputs_in_delta_count: int,
     *     pending_outputs_missing_count: int
     * }
     */
    private static function emptyStructure(int $lastRequestInputCount, int $lastResponseItemCount): array
    {
        return [
            'last_request_input_count' => $lastRequestInputCount,
            'last_response_item_count' => $lastResponseItemCount,
            'pending_function_call_count' => 0,
            'pending_outputs_in_prefix_count' => 0,
            'pending_outputs_in_delta_count' => 0,
            'pending_outputs_missing_count' => 0,
        ];
    }

    /**
     * Locate matching function_call_output items relative to the delta cut without
     * logging call IDs or tool content. Equality uses in-process string identity.
     *
     * @param list<mixed> $lastRequestInput
     * @param list<mixed> $lastResponseItems
     * @param list<mixed> $currentInput
     *
     * @return array{
     *     last_request_input_count: int,
     *     last_response_item_count: int,
     *     pending_function_call_count: int,
     *     pending_outputs_in_prefix_count: int,
     *     pending_outputs_in_delta_count: int,
     *     pending_outputs_missing_count: int
     * }
     */
    private static function structureForDecision(
        array $lastRequestInput,
        array $lastResponseItems,
        array $currentInput,
        int $baselineCount,
    ): array {
        $pendingCallIds = [];
        foreach ($lastResponseItems as $item) {
            if (!\is_array($item) || 'function_call' !== ($item['type'] ?? null)) {
                continue;
            }
            $callId = $item['call_id'] ?? null;
            if (\is_string($callId) && '' !== $callId) {
                $pendingCallIds[$callId] = true;
            }
        }

        $inPrefix = 0;
        $inDelta = 0;
        foreach ($currentInput as $index => $item) {
            if (!\is_array($item) || 'function_call_output' !== ($item['type'] ?? null)) {
                continue;
            }
            $callId = $item['call_id'] ?? null;
            if (!\is_string($callId) || !isset($pendingCallIds[$callId])) {
                continue;
            }
            unset($pendingCallIds[$callId]);
            if ($index < $baselineCount) {
                ++$inPrefix;
            } else {
                ++$inDelta;
            }
        }

        return [
            'last_request_input_count' => \count($lastRequestInput),
            'last_response_item_count' => \count($lastResponseItems),
            'pending_function_call_count' => $inPrefix + $inDelta + \count($pendingCallIds),
            'pending_outputs_in_prefix_count' => $inPrefix,
            'pending_outputs_in_delta_count' => $inDelta,
            'pending_outputs_missing_count' => \count($pendingCallIds),
        ];
    }

    /**
     * Add response-region and encrypted_content length booleans without raw values.
     *
     * @param array{
     *     first_mismatch_index: ?int,
     *     left_item_kind: ?string,
     *     right_item_kind: ?string,
     *     prefix_normalized_equal: bool,
     *     mismatch_field_path: ?string,
     *     mismatch_relation: ?string,
     *     left_value_kind: ?string,
     *     right_value_kind: ?string
     * } $mismatch
     * @param list<mixed> $prefix
     * @param list<mixed> $baseline
     *
     * @return array{
     *     first_mismatch_index: ?int,
     *     left_item_kind: ?string,
     *     right_item_kind: ?string,
     *     prefix_normalized_equal: bool,
     *     mismatch_field_path: ?string,
     *     mismatch_relation: ?string,
     *     left_value_kind: ?string,
     *     right_value_kind: ?string,
     *     mismatch_in_response_items: ?bool,
     *     mismatch_response_item_offset: ?int,
     *     mismatch_ids_equal: ?bool,
     *     mismatch_encrypted_content_left_len: ?int,
     *     mismatch_encrypted_content_right_len: ?int
     * }
     */
    private static function enrichMismatchDiagnostics(
        array $mismatch,
        array $prefix,
        array $baseline,
        int $lastRequestInputCount,
    ): array {
        $index = $mismatch['first_mismatch_index'];
        $inResponseItems = null;
        $responseOffset = null;
        $idsEqual = null;
        $leftLen = null;
        $rightLen = null;

        if (\is_int($index) && $index >= 0 && $index < \count($prefix) && $index < \count($baseline)) {
            $inResponseItems = $index >= $lastRequestInputCount;
            if ($inResponseItems) {
                $responseOffset = $index - $lastRequestInputCount;
            }

            $left = $prefix[$index];
            $right = $baseline[$index];
            if (\is_array($left) && \is_array($right)) {
                $leftId = $left['id'] ?? null;
                $rightId = $right['id'] ?? null;
                if (\is_string($leftId) && '' !== $leftId && \is_string($rightId) && '' !== $rightId) {
                    $idsEqual = $leftId === $rightId;
                }

                $leftEncrypted = $left['encrypted_content'] ?? null;
                $rightEncrypted = $right['encrypted_content'] ?? null;
                if (\is_string($leftEncrypted)) {
                    $leftLen = \strlen($leftEncrypted);
                }
                if (\is_string($rightEncrypted)) {
                    $rightLen = \strlen($rightEncrypted);
                }
            }
        }

        return [
            ...$mismatch,
            'mismatch_in_response_items' => $inResponseItems,
            'mismatch_response_item_offset' => $responseOffset,
            'mismatch_ids_equal' => $idsEqual,
            'mismatch_encrypted_content_left_len' => $leftLen,
            'mismatch_encrypted_content_right_len' => $rightLen,
        ];
    }
}
