<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionMetadata;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class CodexMessageBagNormalizerReasoningTransitionTest extends TestCase
{
    public function testEmitsConfigurationUpdateImmediatelyBeforeMarkedMessageAndKeepsStablePrefix(): void
    {
        $first = Message::ofUser('first');
        $second = Message::ofUser('second');
        $second->getMetadata()->add(CodexReasoningTransitionMetadata::KEY, 'high');
        $third = Message::ofUser('third');

        $contract = CodexContract::create();
        $model = new CodexModel('gpt-6-astra');

        $payload = $contract->createRequestPayload(
            $model,
            new MessageBag($first, $second, $third),
            [],
        );

        $this->assertSame([
            [
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'first']],
            ],
            [
                'type' => 'configuration_update',
                'reasoning' => ['effort' => 'high'],
            ],
            [
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'second']],
            ],
            [
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'third']],
            ],
        ], $payload['input']);

        $fourth = Message::ofUser('fourth');
        $extended = $contract->createRequestPayload(
            $model,
            new MessageBag($first, $second, $third, $fourth),
            [],
        );

        $this->assertSame(
            \array_slice($extended['input'], 0, \count($payload['input'])),
            $payload['input'],
        );
        $this->assertSame([
            [
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'fourth']],
            ],
        ], \array_slice($extended['input'], \count($payload['input'])));
    }

    public function testDoesNotEmitUpdateWithoutMarker(): void
    {
        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-6-astra'),
            new MessageBag(Message::ofUser('hello')),
            [],
        );

        $this->assertSame([
            [
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'hello']],
            ],
        ], $payload['input']);
    }
}
