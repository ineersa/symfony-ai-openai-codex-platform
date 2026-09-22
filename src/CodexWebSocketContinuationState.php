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
     * @param array<string, mixed> $currentRequestBody
     *
     * @return array{previous_response_id: string, input: list<array<string, mixed>>}|null
     */
    public function buildDeltaRequest(array $currentRequestBody): ?array
    {
        if (!CodexWebSocketContinuationComparator::requestBodiesMatchExceptInput($currentRequestBody, $this->lastRequestBody)) {
            return null;
        }

        $currentInput = $currentRequestBody['input'] ?? [];
        if (!\is_array($currentInput)) {
            return null;
        }

        /** @var list<array<string, mixed>> $baseline */
        $baseline = array_merge(
            $this->lastRequestBody['input'] ?? [],
            $this->lastResponseItems,
        );

        if (\count($currentInput) < \count($baseline)) {
            return null;
        }

        $prefix = \array_slice($currentInput, 0, \count($baseline));
        if (!CodexWebSocketContinuationComparator::responseInputsEqual($prefix, $baseline)) {
            return null;
        }

        return [
            'previous_response_id' => $this->lastResponseId,
            'input' => \array_slice($currentInput, \count($baseline)),
        ];
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
}
