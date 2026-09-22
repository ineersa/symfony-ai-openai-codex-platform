<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Support\CodexResponsesToolCallId;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Normalizes ToolCall instances into Codex Responses API format.
 *
 * Produces flat {call_id, name, arguments, type: 'function_call'} entries
 * for tool calls embedded in assistant messages within the input array.
 *
 * Item id is optional and only emitted for native `fc_*` Responses item
 * ids. Foreign bare chat-completions ids stay on call_id alone so Codex
 * does not reject them as invalid item ids.
 *
 * This differs from the default Symfony AI ToolCallNormalizer
 * which nests under {id, type: 'function', function: {name, arguments}}.
 */
final class CodexToolCallNormalizer extends ModelContractNormalizer
{
    /**
     * @return array{
     *     call_id: string,
     *     name: string,
     *     arguments: string,
     *     type: 'function_call',
     *     id?: string
     * }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $parts = CodexResponsesToolCallId::split($data->getId());

        $normalized = [
            'call_id' => $parts['call_id'],
            'name' => $data->getName(),
            'arguments' => json_encode($data->getArguments() ?? new \stdClass()),
            'type' => 'function_call',
        ];

        if (CodexResponsesToolCallId::isNativeItemId($parts['item_id'])) {
            $normalized['id'] = $parts['item_id'];
        }

        return $normalized;
    }

    protected function supportedDataClass(): string
    {
        return ToolCall::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
