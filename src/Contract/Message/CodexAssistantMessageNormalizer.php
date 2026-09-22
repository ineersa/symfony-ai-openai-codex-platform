<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Normalizes AssistantMessages into the Codex Responses API format.
 *
 * Emits reasoning items from thinking signatures, typed message content for
 * visible text, and function_call items for tool calls. These may all appear
 * in one assistant turn and are flattened by CodexMessageBagNormalizer.
 *
 * Request conversion removes incompatible thinking before normalization.
 */
final class CodexAssistantMessageNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $text = '';
        $thinkingSignature = null;
        $preserveNativeItemIds = true === $data->getMetadata()->get(
            'preserve_native_item_ids',
            true,
        );

        foreach ($data->getContent() as $part) {
            if ($part instanceof Text) {
                $text .= $part->getText();
            }

            if ($part instanceof Thinking) {
                $sig = $part->getSignature();
                if (\is_string($sig) && '' !== $sig) {
                    $thinkingSignature = $sig;
                }
            }
        }

        $output = [];

        if (null !== $thinkingSignature) {
            $output[] = json_decode($thinkingSignature, true, flags: \JSON_THROW_ON_ERROR);
        }

        if ('' !== $text) {
            $output[] = [
                'role' => $data->getRole()->value,
                'type' => 'message',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ];
        }

        if ($data->hasToolCalls()) {
            /** @var list<ToolCall> $toolCalls */
            $toolCalls = $data->getToolCalls();
            $normalizedToolCalls = $this->normalizer->normalize($toolCalls, $format, $context);
            if (\is_array($normalizedToolCalls) && array_is_list($normalizedToolCalls)) {
                foreach ($normalizedToolCalls as $toolCall) {
                    if (\is_array($toolCall)) {
                        if (!$preserveNativeItemIds) {
                            unset($toolCall['id']);
                        }
                        $output[] = $toolCall;
                    }
                }
            }
        }

        if ([] === $output) {
            return [];
        }

        return 1 === \count($output) ? $output[0] : $output;
    }

    protected function supportedDataClass(): string
    {
        return AssistantMessage::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
