<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Normalizes UserMessage into the Codex Responses API typed format.
 *
 * Produces {role: 'user', content: [{type: 'input_text', text: '...'}]}
 * instead of the default Chat Completions format {role: 'user', content: 'text'}.
 *
 * The content is always rendered as a typed content array via the normalizer
 * chain, which picks up CodexTextNormalizer for Text content parts.
 * This matches the Responses API contract (OpenResponses bridge pattern).
 */
final class CodexUserMessageNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array{
     *     role: 'user',
     *     content: list<array{type: string, text: string}>
     * }
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return [
            'role' => $data->getRole()->value,
            'content' => $this->normalizer->normalize($data->getContent(), $format, $context),
        ];
    }

    protected function supportedDataClass(): string
    {
        return UserMessage::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
