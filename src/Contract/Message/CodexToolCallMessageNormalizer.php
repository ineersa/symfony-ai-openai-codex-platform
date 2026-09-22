<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Support\CodexResponsesToolCallId;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Model;

/**
 * Codex tool-result normalizer.
 *
 * Emits function_call_output with the Responses call_id half only, so
 * composite stored ids (call_id|fc_*) pair with function_call.call_id.
 */
final class CodexToolCallMessageNormalizer extends ModelContractNormalizer
{
    /**
     * @return array{
     *     type: 'function_call_output',
     *     call_id: string,
     *     output: string
     * }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $parts = CodexResponsesToolCallId::split($data->getToolCall()->getId());

        return [
            'type' => 'function_call_output',
            'call_id' => $parts['call_id'],
            'output' => $data->asText() ?? '',
        ];
    }

    protected function supportedDataClass(): string
    {
        return ToolCallMessage::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
