<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexToolCallNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexToolNormalizer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\Message\CodexAssistantMessageNormalizer;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

final class CodexContractTest extends TestCase
{
    /**
     * Verify that CodexContract::createRequestPayload produces the Responses API
     * format: {input: [...], instructions: "..."} instead of Chat Completions
     * format {messages: [...]}.
     *
     * @param array<string, mixed> $expected
     */
    #[DataProvider('requestPayloadProvider')]
    public function testCreateRequestPayload(MessageBag $messageBag, array $expected): void
    {
        $contract = CodexContract::create();
        $model = new CodexModel('gpt-5.5');

        $payload = $contract->createRequestPayload($model, $messageBag, []);

        $this->assertEquals($expected, $payload);
    }

    public static function requestPayloadProvider(): \Generator
    {
        yield 'user message only' => [
            new MessageBag(Message::ofUser('Hello')),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Hello'],
                        ],
                    ],
                ],
            ],
        ];

        yield 'system + user message' => [
            new MessageBag(
                Message::forSystem('You are a helpful assistant.'),
                Message::ofUser('What is the weather?'),
            ),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'What is the weather?'],
                        ],
                    ],
                ],
                'instructions' => 'You are a helpful assistant.',
            ],
        ];

        yield 'multi-turn conversation' => [
            new MessageBag(
                Message::ofUser('Hello'),
                Message::ofAssistant('Hi! How can I help?'),
                Message::ofUser('Tell me a joke.'),
            ),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Hello'],
                        ],
                    ],
                    [
                        'role' => 'assistant',
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'Hi! How can I help?'],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Tell me a joke.'],
                        ],
                    ],
                ],
            ],
        ];

        yield 'system + conversation' => [
            new MessageBag(
                Message::forSystem('You are a code assistant.'),
                Message::ofUser('Write a function'),
                Message::ofAssistant('Here is the code.'),
            ),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Write a function'],
                        ],
                    ],
                    [
                        'role' => 'assistant',
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'Here is the code.'],
                        ],
                    ],
                ],
                'instructions' => 'You are a code assistant.',
            ],
        ];

        yield 'with tool call in assistant message' => [
            new MessageBag(
                Message::ofUser('Roll a die'),
                Message::ofAssistant(
                    new ToolCall('call-123', 'roll-die', ['sides' => 6]),
                ),
            ),
            [
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => 'Roll a die'],
                        ],
                    ],
                    [
                        'call_id' => 'call-123',
                        'name' => 'roll-die',
                        'arguments' => json_encode(['sides' => 6]),
                        'type' => 'function_call',
                    ],
                ],
            ],
        ];
    }

    public function testUserImageUsesResponsesInputImageShape(): void
    {
        $dataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $messageBag = new MessageBag(Message::ofUser(
            new Text('Inspect this image.'),
            Image::fromDataUrl($dataUrl),
        ));

        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-5.5', [Capability::INPUT_IMAGE]),
            $messageBag,
            [],
        );

        $this->assertSame([
            'type' => 'input_image',
            'image_url' => $dataUrl,
        ], $payload['input'][0]['content'][1]);
    }

    public function testToolNormalizerIncludesStrictNull(): void
    {
        $normalizer = new CodexToolNormalizer();
        $tool = new Tool(
            reference: new ExecutionReference(self::class),
            name: 'get_weather',
            description: 'Get current weather',
            parameters: ['type' => 'object', 'properties' => ['location' => ['type' => 'string', 'description' => 'City']], 'required' => ['location'], 'additionalProperties' => false],
        );

        $result = $normalizer->normalize($tool, context: ['model' => new CodexModel('gpt-5.5')]);

        $this->assertSame('function', $result['type']);
        $this->assertSame('get_weather', $result['name']);
        $this->assertSame('Get current weather', $result['description']);
        $this->assertArrayHasKey('strict', $result);
        $this->assertNull($result['strict']);
        $this->assertArrayHasKey('parameters', $result);
    }

    public function testToolNormalizerOmitsStrictWhenNoParameters(): void
    {
        $normalizer = new CodexToolNormalizer();
        $tool = new Tool(
            reference: new ExecutionReference(self::class),
            name: 'no_params',
            description: 'Tool with no parameters',
        );

        $result = $normalizer->normalize($tool, context: ['model' => new CodexModel('gpt-5.5')]);

        $this->assertArrayNotHasKey('parameters', $result);
        $this->assertArrayNotHasKey('strict', $result);
    }

    public function testToolCallNormalizerKeepsForeignCallIdWithoutItemId(): void
    {
        $normalizer = new CodexToolCallNormalizer();
        $toolCall = new ToolCall('call-xyz', 'search', ['q' => 'test']);

        $result = $normalizer->normalize($toolCall, context: ['model' => new CodexModel('gpt-5.5')]);

        $this->assertArrayNotHasKey('id', $result);
        $this->assertSame('call-xyz', $result['call_id']);
        $this->assertSame('search', $result['name']);
        $this->assertSame('function_call', $result['type']);
        $this->assertStringContainsString('"q"', $result['arguments']);
    }

    public function testToolCallNormalizerEmitsNativeItemIdWhenPresent(): void
    {
        $normalizer = new CodexToolCallNormalizer();
        $toolCall = new ToolCall('call_abc|fc_abc', 'search', ['q' => 'test']);

        $result = $normalizer->normalize($toolCall, context: ['model' => new CodexModel('gpt-5.5')]);

        $this->assertSame('fc_abc', $result['id']);
        $this->assertSame('call_abc', $result['call_id']);
    }

    public function testForeignToolCallPlusTextAndThinkingNormalizeWithoutItemId(): void
    {
        $assistant = new AssistantMessage(
            new Text("Calling the tool.\n\nPlan the call."),
            new ToolCall('call_845adac454c64712b769d15b', 'bash', ['command' => 'pwd']),
        );
        $assistant->getMetadata()->set([
            'preserve_native_item_ids' => false,
        ]);

        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-6-astra'),
            new MessageBag(
                Message::ofUser('Continue'),
                $assistant,
                Message::ofToolCall(
                    new ToolCall('call_845adac454c64712b769d15b', 'bash', ['command' => 'pwd']),
                    'ok',
                ),
            ),
            [],
        );

        $this->assertSame('user', $payload['input'][0]['role']);
        $this->assertSame('message', $payload['input'][1]['type']);
        $this->assertSame("Calling the tool.\n\nPlan the call.", $payload['input'][1]['content'][0]['text']);
        $functionCall = $payload['input'][2];
        $this->assertSame('function_call', $functionCall['type']);
        $this->assertSame('call_845adac454c64712b769d15b', $functionCall['call_id']);
        $this->assertArrayNotHasKey('id', $functionCall);
        $this->assertSame('function_call_output', $payload['input'][3]['type']);
        $this->assertSame('call_845adac454c64712b769d15b', $payload['input'][3]['call_id']);
    }

    public function testSameModelNativeItemIdIsPreserved(): void
    {
        $assistant = new AssistantMessage(
            new ToolCall('call_native|fc_native', 'bash', ['command' => 'pwd']),
        );
        $assistant->getMetadata()->set([
            'preserve_native_item_ids' => true,
        ]);

        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-6-astra'),
            new MessageBag($assistant),
            [],
        );

        $this->assertSame('fc_native', $payload['input'][0]['id']);
        $this->assertSame('call_native', $payload['input'][0]['call_id']);
    }

    public function testNativeCompositeIdsPairFunctionCallWithToolResult(): void
    {
        $assistant = new AssistantMessage(
            new ToolCall('call_native|fc_native', 'bash', ['command' => 'pwd']),
        );
        $assistant->getMetadata()->set([
            'preserve_native_item_ids' => true,
        ]);

        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-6-astra'),
            new MessageBag(
                $assistant,
                Message::ofToolCall(
                    new ToolCall('call_native|fc_native', 'bash', ['command' => 'pwd']),
                    'ok',
                ),
            ),
            [],
        );

        $this->assertSame('function_call', $payload['input'][0]['type']);
        $this->assertSame('fc_native', $payload['input'][0]['id']);
        $this->assertSame('call_native', $payload['input'][0]['call_id']);
        $this->assertSame('function_call_output', $payload['input'][1]['type']);
        $this->assertSame('call_native', $payload['input'][1]['call_id']);
    }

    public function testChangedModelOmitsNativeItemIds(): void
    {
        $assistant = new AssistantMessage(
            new Text('plan'),
            new ToolCall('call_native|fc_native', 'bash', ['command' => 'pwd']),
        );
        $assistant->getMetadata()->set([
            'preserve_native_item_ids' => false,
        ]);

        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-6-astra'),
            new MessageBag($assistant),
            [],
        );

        $this->assertSame('message', $payload['input'][0]['type']);
        $this->assertSame('plan', $payload['input'][0]['content'][0]['text']);
        $this->assertSame('function_call', $payload['input'][1]['type']);
        $this->assertSame('call_native', $payload['input'][1]['call_id']);
        $this->assertArrayNotHasKey('id', $payload['input'][1]);
    }

    // -- Tool result (function_call_output) regression guard (#182) --

    /**
     * Regression test: a ToolCallMessage (tool RESULT) must normalize to
     * {type:'function_call_output', call_id, output} for CodexModel, NOT
     * to {role:'tool',…}.  The Codex Responses API rejects the Chat
     * Completions role:'tool' shape with HTTP 400.
     *
     * This test FAILS without the OpenResponses ToolCallMessageNormalizer
     * registered explicitly in CodexContract::create() (the core
     * Platform\…\ToolCallMessageNormalizer appended by Contract::create()
     * would produce role:'tool' instead).
     */
    public function testToolCallMessageNormalizesToFunctionCallOutput(): void
    {
        $messageBag = new MessageBag(
            Message::ofUser('Read file x'),
            Message::ofToolCall(
                new ToolCall('call_abc', 'read_file', ['path' => 'x']),
                'file contents here',
            ),
        );

        $contract = CodexContract::create();
        $model = new CodexModel('gpt-5.5');

        $payload = $contract->createRequestPayload($model, $messageBag, []);

        $this->assertArrayHasKey('input', $payload);
        $input = $payload['input'];

        // Item 0: user message
        $this->assertCount(2, $input);

        // Item 1 must be the function_call_output, NOT role:'tool'
        $toolResult = $input[1];
        $this->assertArrayHasKey('type', $toolResult);
        $this->assertSame('function_call_output', $toolResult['type']);
        $this->assertSame('call_abc', $toolResult['call_id']);
        $this->assertSame('file contents here', $toolResult['output']);

        // Must NOT contain the Chat Completions shape
        $this->assertArrayNotHasKey('role', $toolResult, 'Tool result must not use role:tool shape (Chat Completions)');
    }

    // -- Thinking / reasoning round-trip via CodexAssistantMessageNormalizer --

    /**
     * Regression test for #177: a thinking-only AssistantMessage with a
     * signature must normalize to a SEPARATE reasoning input item, NOT
     * a message item with content:null (which causes HTTP 400).
     */
    public function testAssistantWithThinkingSignatureEmitsReasoningItem(): void
    {
        $thinking = new Thinking(
            content: 'Let me reason',
            signature: '{"type":"reasoning","id":"rs_1","encrypted_content":"enc_xyz"}',
        );
        $assistantMessage = new AssistantMessage($thinking);

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        // Should return a reasoning item, not a message with content:null
        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertSame('reasoning', $result['type']);
        $this->assertSame('rs_1', $result['id']);
        $this->assertSame('enc_xyz', $result['encrypted_content']);
    }

    /**
     * When an AssistantMessage has both text AND thinking with signature,
     * the normalizer must emit TWO items (reasoning + message), not one.
     */
    public function testAssistantWithTextAndThinkingSignatureEmitsBothItems(): void
    {
        $thinking = new Thinking(
            content: 'Reasoning here',
            signature: '{"type":"reasoning","id":"rs_2","encrypted_content":"enc_abc"}',
        );
        $text = new Text('The answer is 42');
        $assistantMessage = new AssistantMessage($thinking, $text);

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        // Should return an array of 2 items: reasoning + message
        $this->assertIsArray($result);
        $this->assertArrayHasKey(0, $result, 'Should be a list (numeric index 0)');
        $this->assertArrayHasKey(1, $result, 'Should be a list with 2 items');
        $this->assertSame('reasoning', $result[0]['type']);
        $this->assertSame('message', $result[1]['type']);
        $this->assertArrayHasKey('content', $result[1]);
        $this->assertNotNull($result[1]['content'], 'Content must not be null when text is present');
    }

    public function testAssistantWithInterleavedThinkingAndTextPreservesOrder(): void
    {
        $first = new Thinking(
            content: 'first',
            signature: '{"type":"reasoning","id":"rs_1","encrypted_content":"enc_1"}',
        );
        $text = new Text('commentary');
        $second = new Thinking(
            content: 'second',
            signature: '{"type":"reasoning","id":"rs_2","encrypted_content":"enc_2"}',
        );
        $assistantMessage = new AssistantMessage($first, $text, $second);

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertSame('reasoning', $result[0]['type']);
        $this->assertSame('rs_1', $result[0]['id']);
        $this->assertSame('message', $result[1]['type']);
        $this->assertSame('commentary', $result[1]['content'][0]['text'] ?? null);
        $this->assertSame('reasoning', $result[2]['type']);
        $this->assertSame('rs_2', $result[2]['id']);
    }

    /**
     * Regression test for #177: an empty assistant message must NOT produce
     * a content:null message item.  It must return an empty array so the
     * MessageBag normalizer skips that turn entirely.
     */
    public function testEmptyAssistantMessageProducesNoInputItem(): void
    {
        $assistantMessage = new AssistantMessage();

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        // Empty array = nothing to emit (NOT content:null)
        $this->assertSame([], $result);
    }

    /**
     * A thinking-only message WITHOUT a signature (e.g. reasoning that
     * was never captured in a prior turn) must also produce nothing —
     * there is no reasoning item to replay and no text to display.
     */
    public function testThinkingOnlyWithoutSignatureProducesNoInputItem(): void
    {
        $thinking = new Thinking(
            content: 'Reasoning without signature',
            signature: null,
        );
        $assistantMessage = new AssistantMessage($thinking);

        $normalizer = new CodexAssistantMessageNormalizer();
        $result = $normalizer->normalize(
            $assistantMessage,
            null,
            ['model' => new CodexModel('gpt-5.5')],
        );

        // No signature → no reasoning item. No text → no message item.
        $this->assertSame([], $result);
    }

    /**
     * Integration test: CodexContract with CodexMessageBagNormalizer must
     * flatten a thinking+text assistant message into two separate input items
     * (reasoning item + message item), not a nested array.
     */
    public function testThinkingWithSignatureProducesFlatInputItems(): void
    {
        $thinking = new Thinking(
            content: 'Let me reason',
            signature: '{"type":"reasoning","id":"rs_1","encrypted_content":"enc_xyz"}',
        );
        $text = new Text('The answer');

        $messageBag = new MessageBag(
            Message::ofUser('Hello'),
            new AssistantMessage($thinking, $text),
        );

        $contract = CodexContract::create();
        $model = new CodexModel('gpt-5.5');

        $payload = $contract->createRequestPayload($model, $messageBag, []);

        $this->assertArrayHasKey('input', $payload);
        $input = $payload['input'];

        // User message (item 0) + reasoning item (item 1) + assistant message (item 2)
        $this->assertCount(3, $input);

        // User message
        $this->assertSame('user', $input[0]['role']);

        // Reasoning item is a SEPARATE top-level input item, not nested
        $this->assertSame('reasoning', $input[1]['type']);
        $this->assertSame('rs_1', $input[1]['id']);

        // Assistant message is the third item, with proper content (not null)
        $this->assertSame('assistant', $input[2]['role']);
        $this->assertSame('message', $input[2]['type']);
        $this->assertIsArray($input[2]['content']);
        $this->assertNotNull($input[2]['content']);
        $this->assertNotEmpty($input[2]['content']);
    }
}
