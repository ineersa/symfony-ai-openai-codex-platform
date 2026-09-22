<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message;

use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionMetadata;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Codex-specific MessageBag normalizer.
 *
 * Extends the OpenResponses pattern with three additional behaviors:
 * 1. Flattens multi-item normalizer output (reasoning + message) into the
 *    input array, not as a nested array.
 * 2. Skips messages that normalize to an empty array (thinking-only messages
 *    with no signature produce no input items).
 * 3. Emits history-bound configuration_update items immediately before each
 *    message that carries a codex_reasoning_effort marker.
 *
 * This is separated from the OpenResponses MessageBagNormalizer because the
 * Codex contract must flatten reasoning items (separate top-level input items
 * emitted by CodexAssistantMessageNormalizer) rather than nesting them.
 */
final class CodexMessageBagNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array{
     *     input: list<mixed>,
     *     instructions?: string,
     * }
     *
     * @throws ExceptionInterface
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof MessageBag) {
            throw new \InvalidArgumentException(\sprintf('Expected %s, got %s.', MessageBag::class, get_debug_type($data)));
        }

        /** @var list<mixed> $input */
        $input = [];

        foreach ($data->withoutSystemMessage()->getMessages() as $message) {
            if (!$message instanceof MessageInterface) {
                continue;
            }

            $normalized = $this->normalizer->normalize($message, $format, $context);

            // Skip empty results (thinking-only messages with no signature
            // return [] from CodexAssistantMessageNormalizer).
            if (\is_array($normalized) && [] === $normalized) {
                continue;
            }

            $effort = $message->getMetadata()->get(CodexReasoningTransitionMetadata::KEY);
            if (\is_string($effort) && '' !== $effort) {
                $input[] = [
                    'type' => 'configuration_update',
                    'reasoning' => ['effort' => $effort],
                ];
            }

            // Flatten when the normalized result is a sequential list.
            // array_is_list handles:
            //   - Tool-call assistant messages (return a list of function_call items)
            //   - Thinking+text assistant messages (return [reasoning_item, message_item])
            if (\is_array($normalized) && array_is_list($normalized)) {
                foreach ($normalized as $item) {
                    $input[] = $item;
                }
            } else {
                // Single associative item or unexpected scalar — append as-is.
                $input[] = $normalized;
            }
        }

        $messages = ['input' => $input];

        $systemMessage = $data->getSystemMessage();
        if (null !== $systemMessage) {
            $content = $systemMessage->getContent();
            $messages['instructions'] = \is_string($content) ? $content : (string) $content;
        }

        return $messages;
    }

    protected function supportedDataClass(): string
    {
        return MessageBag::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof CodexModel;
    }
}
