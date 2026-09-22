<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Normalizes Tool definitions into Codex Responses API format.
 *
 * Produces flat {type: 'function', name, description, parameters}
 * without the Chat Completions `function` wrapper.
 *
 * This matches the OpenResponses bridge ToolNormalizer pattern.
 */
final class CodexToolNormalizer extends ModelContractNormalizer
{
    /**
     * @return array{
     *     type: 'function',
     *     name: string,
     *     description: string,
     *     parameters?: array<string, mixed>,
     *     strict?: null
     * }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $function = [
            'type' => 'function',
            'name' => $data->getName(),
            'description' => $data->getDescription(),
        ];

        if (null !== $data->getParameters()) {
            $function['parameters'] = $data->getParameters();
            $function['strict'] = null;
        }

        return $function;
    }

    protected function supportedDataClass(): string
    {
        return Tool::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
