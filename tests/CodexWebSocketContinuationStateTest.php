<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationDecision;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationState;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Uid\UuidV7;

final class CodexWebSocketContinuationStateTest extends TestCase
{
    public function testToolChangeDoesNotHideAnUnrelatedHistoryOrBodyMismatch(): void
    {
        $body = [
            'model' => 'gpt-5.6-luna',
            'input' => [['role' => 'user', 'content' => 'first']],
            'tools' => [['name' => 'read']],
        ];
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $body,
            'resp_first',
            [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
        );
        $current = [
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'next'],
            ],
            'tools' => [['name' => 'new_mcp_tool']],
        ];

        $this->assertTrue($state->requiresFreshChainForTools($current));
        $current['input'][1]['content'] = 'different reply';
        $this->assertFalse($state->requiresFreshChainForTools($current));
        $current['input'][1]['content'] = 'ok';
        $current['model'] = 'different-model';
        $this->assertFalse($state->requiresFreshChainForTools($current));
    }

    public function testNormalizedAssistantHistoryContinuesNativeResponseWithoutReplayingIt(): void
    {
        $contract = CodexContract::create();
        $model = new CodexModel('gpt-6-astra');
        $user = Message::ofUser('first');
        $body = $contract->createRequestPayload($model, new MessageBag($user), []);
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse($body, 'resp_text', [[
            'id' => 'msg_native',
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'phase' => 'final_answer',
            'content' => [['type' => 'output_text', 'annotations' => [], 'logprobs' => [], 'text' => 'answer']],
        ]]);
        $current = $contract->createRequestPayload($model, new MessageBag($user, Message::ofAssistant('answer'), Message::ofUser('next')), []);

        $delta = $state->decide($current)->delta;
        $this->assertNotNull($delta);
        $this->assertSame('resp_text', $delta['previous_response_id']);
        $this->assertSame([$current['input'][2]], $delta['input']);

        $current['input'][1]['content'][0]['text'] = 'edited answer';
        $this->assertNull($state->decide($current)->delta, 'Changed assistant content must still reject continuation.');
    }

    public function testNormalizedToolHistoryContinuesNativeResponseWithoutReplayingCall(): void
    {
        $contract = CodexContract::create();
        $model = new CodexModel('gpt-6-astra');
        $user = Message::ofUser('read fixture');
        $body = $contract->createRequestPayload($model, new MessageBag($user), []);
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse($body, 'resp_tool', [[
            'id' => 'fc_native',
            'type' => 'function_call',
            'status' => 'completed',
            'call_id' => 'call_native',
            'name' => 'read',
            'arguments' => '{ "path": "./probe.txt" }',
        ]]);
        $call = new ToolCall('call_native|fc_native', 'read', ['path' => './probe.txt']);
        $current = $contract->createRequestPayload($model, new MessageBag(
            $user,
            Message::ofAssistant(new ToolCallResult([$call])),
            Message::ofToolCall($call, 'fixture'),
        ), []);

        $delta = $state->decide($current)->delta;
        $this->assertNotNull($delta);
        $this->assertSame('resp_tool', $delta['previous_response_id']);
        $this->assertSame([$current['input'][2]], $delta['input']);

        $changedCall = $current;
        $changedCall['input'][1]['call_id'] = 'call_other';
        $this->assertNull($state->decide($changedCall)->delta, 'A different call must not inherit the response.');
        $reordered = $current;
        [$reordered['input'][0], $reordered['input'][1]] = [$reordered['input'][1], $reordered['input'][0]];
        $this->assertNull($state->decide($reordered)->delta, 'Object key order is irrelevant, but input item order is not.');

        $current['input'][1]['arguments'] = '{"path":"./different.txt"}';
        $this->assertNull($state->decide($current)->delta, 'Changed tool arguments must still reject continuation.');
    }

    public function testBuildsDeltaForStrictExtension(): void
    {
        $baselineBody = [
            'model' => 'gpt-5.6-luna',
            'input' => [['role' => 'user', 'content' => 'first']],
            'stream' => true,
        ];
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $baselineBody,
            'resp_123',
            [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
        );

        $current = [
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second'],
            ],
            'stream' => true,
        ];

        $delta = $state->decide($current)->delta;
        $this->assertNotNull($delta);
        $this->assertSame('resp_123', $delta['previous_response_id']);
        $this->assertCount(1, $delta['input']);
        $this->assertSame('second', $delta['input'][0]['content']);
    }

    public function testDivergentBodyReturnsNull(): void
    {
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['model' => 'gpt-5.6-luna', 'input' => [], 'stream' => true],
            'resp_123',
            [],
        );

        $delta = $state->decide([
            'model' => 'gpt-5.6-sol',
            'input' => [['role' => 'user', 'content' => 'x']],
            'stream' => true,
        ])->delta;

        $this->assertNull($delta);
    }

    public function testDecideClassifiesBodyMismatchSeparatelyFromPrefixMismatch(): void
    {
        $stableKey = UuidV7::v7()->toRfc4122();
        $changedKey = UuidV7::v7()->toRfc4122();
        $baselineBody = [
            'model' => 'gpt-5.6-luna',
            'prompt_cache_key' => $stableKey,
            'input' => [['role' => 'user', 'content' => 'first']],
            'stream' => true,
        ];
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $baselineBody,
            'resp_body',
            [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
        );

        $bodyMismatch = $state->decide([
            'model' => 'gpt-5.6-sol',
            'prompt_cache_key' => $stableKey,
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);
        $this->assertSame(CodexWebSocketContinuationDecision::REASON_DIVERGENT_BODY, $bodyMismatch->reason);
        $this->assertNull($bodyMismatch->delta);
        $this->assertTrue($bodyMismatch->promptCacheKeyPresent);
        $this->assertSame(16, \strlen((string) $bodyMismatch->promptCacheKeyFp));
        $this->assertFalse($bodyMismatch->promptCacheKeyChanged);
        $this->assertStringNotContainsString($stableKey, (string) $bodyMismatch->promptCacheKeyFp);

        $prefixMismatch = $state->decide([
            'model' => 'gpt-5.6-luna',
            'prompt_cache_key' => $changedKey,
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'edited'],
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);
        $this->assertSame(CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH, $prefixMismatch->reason);
        $this->assertNull($prefixMismatch->delta);
        $this->assertTrue($prefixMismatch->promptCacheKeyChanged);
        $this->assertFalse($prefixMismatch->prefixNormalizedEqual);
        $this->assertSame(1, $prefixMismatch->firstMismatchIndex);
        $this->assertSame('message', $prefixMismatch->leftItemKind);
        $this->assertSame('message', $prefixMismatch->rightItemKind);
        $this->assertSame(16, \strlen((string) $prefixMismatch->promptCacheKeyFp));
        $this->assertNotSame($bodyMismatch->promptCacheKeyFp, $prefixMismatch->promptCacheKeyFp);
        $encoded = json_encode($prefixMismatch->toLogContext(), \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($stableKey, $encoded);
        $this->assertStringNotContainsString($changedKey, $encoded);
    }

    public function testPromptCacheKeyFingerprintNeverIncludesRawKey(): void
    {
        $uuidKey = UuidV7::v7()->toRfc4122();
        $context = CodexWebSocketContinuationDecision::promptCacheKeyContext([
            'prompt_cache_key' => $uuidKey,
        ]);

        $this->assertTrue($context['prompt_cache_key_present']);
        $this->assertSame(\strlen($uuidKey), $context['prompt_cache_key_length']);
        $this->assertSame(16, \strlen((string) $context['prompt_cache_key_fp']));
        $this->assertStringNotContainsString($uuidKey, (string) $context['prompt_cache_key_fp']);

        $lowEntropy = CodexWebSocketContinuationDecision::promptCacheKeyContext([
            'prompt_cache_key' => 'session-1',
        ]);
        $this->assertTrue($lowEntropy['prompt_cache_key_present']);
        $this->assertSame(9, $lowEntropy['prompt_cache_key_length']);
        $this->assertNull($lowEntropy['prompt_cache_key_fp']);

        $this->assertFalse(CodexWebSocketContinuationDecision::promptCacheKeyChanged(
            ['prompt_cache_key' => 'same'],
            ['prompt_cache_key' => 'same'],
        ));
        $this->assertTrue(CodexWebSocketContinuationDecision::promptCacheKeyChanged(
            ['prompt_cache_key' => 'a'],
            ['prompt_cache_key' => 'b'],
        ));
    }

    public function testPrefixMismatchItemKindsAreBoundedVocabulary(): void
    {
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['model' => 'gpt-5.6-luna', 'input' => [['role' => 'user', 'content' => 'first']], 'stream' => true],
            'resp_kinds',
            [['type' => 'totally_custom_provider_type', 'payload' => 'secret']],
        );

        $decision = $state->decide([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'another_custom_type', 'payload' => 'secret'],
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);

        $this->assertSame(CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH, $decision->reason);
        $this->assertSame('other', $decision->leftItemKind);
        $this->assertSame('other', $decision->rightItemKind);
        $this->assertStringNotContainsString('totally_custom_provider_type', json_encode($decision->toLogContext(), \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret', json_encode($decision->toLogContext(), \JSON_THROW_ON_ERROR));
    }

    public function testReasoningPrefixMismatchReportsAllowlistedFieldPathWithoutRawValues(): void
    {
        $terminalReasoning = [
            'type' => 'reasoning',
            'id' => 'rs_terminal',
            'status' => 'completed',
            'encrypted_content' => 'enc_terminal_secret',
            'summary' => [['type' => 'summary_text', 'text' => 'plan A']],
            'provider_debug' => 'do-not-log',
        ];
        $historyReasoning = [
            'type' => 'reasoning',
            'id' => 'rs_history',
            'encrypted_content' => 'enc_history_secret',
            'summary' => [['type' => 'summary_text', 'text' => 'plan A']],
            'provider_debug' => 'also-secret',
        ];

        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['model' => 'gpt-5.6-luna', 'input' => [['role' => 'user', 'content' => 'first']], 'stream' => true],
            'resp_reasoning_fields',
            [$terminalReasoning],
        );

        $decision = $state->decide([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                $historyReasoning,
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);

        $this->assertSame(CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH, $decision->reason);
        $this->assertSame(1, $decision->firstMismatchIndex);
        $this->assertSame('reasoning', $decision->leftItemKind);
        $this->assertSame('reasoning', $decision->rightItemKind);
        $this->assertSame('encrypted_content', $decision->mismatchFieldPath);
        $this->assertSame('different', $decision->mismatchRelation);
        $this->assertSame('string', $decision->leftValueKind);
        $this->assertSame('string', $decision->rightValueKind);
        $this->assertTrue($decision->mismatchInResponseItems);
        $this->assertSame(0, $decision->mismatchResponseItemOffset);
        $this->assertFalse($decision->mismatchIdsEqual);
        $this->assertSame(\strlen('enc_history_secret'), $decision->mismatchEncryptedContentLeftLen);
        $this->assertSame(\strlen('enc_terminal_secret'), $decision->mismatchEncryptedContentRightLen);
        $this->assertSame(1, $decision->lastRequestInputCount);
        $this->assertSame(1, $decision->lastResponseItemCount);
        $this->assertSame(0, $decision->pendingFunctionCallCount);

        $encoded = json_encode($decision->toLogContext(), \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('enc_terminal_secret', $encoded);
        $this->assertStringNotContainsString('enc_history_secret', $encoded);
        $this->assertStringNotContainsString('provider_debug', $encoded);
        $this->assertStringNotContainsString('do-not-log', $encoded);
        $this->assertStringNotContainsString('rs_terminal', $encoded);
        $this->assertStringNotContainsString('rs_history', $encoded);
    }

    public function testReasoningPrefixMismatchReportsAbsentEncryptedContentWithoutHashing(): void
    {
        $withEncrypted = [
            'type' => 'reasoning',
            'encrypted_content' => 'enc_present',
            'summary' => [['type' => 'summary_text', 'text' => 'same']],
        ];
        $withoutEncrypted = [
            'type' => 'reasoning',
            'summary' => [['type' => 'summary_text', 'text' => 'same']],
        ];

        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['model' => 'gpt-5.6-luna', 'input' => [['role' => 'user', 'content' => 'first']], 'stream' => true],
            'resp_reasoning_absent',
            [$withEncrypted],
        );

        $decision = $state->decide([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                $withoutEncrypted,
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);

        $this->assertSame(CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH, $decision->reason);
        $this->assertSame('encrypted_content', $decision->mismatchFieldPath);
        $this->assertSame('absent_left', $decision->mismatchRelation);
        $this->assertSame('absent', $decision->leftValueKind);
        $this->assertSame('string', $decision->rightValueKind);
        $this->assertStringNotContainsString('enc_present', json_encode($decision->toLogContext(), \JSON_THROW_ON_ERROR));
    }

    public function testUnexpectedProviderKeysNeverAppearInMismatchFieldPath(): void
    {
        $left = [
            'type' => 'reasoning',
            'summary' => [['type' => 'summary_text', 'text' => 'same']],
            'totally_custom_blob' => ['nested' => 'secret-left'],
        ];
        $right = [
            'type' => 'reasoning',
            'summary' => [['type' => 'summary_text', 'text' => 'same']],
            'totally_custom_blob' => ['nested' => 'secret-right'],
        ];

        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['model' => 'gpt-5.6-luna', 'input' => [['role' => 'user', 'content' => 'first']], 'stream' => true],
            'resp_custom_keys',
            [$right],
        );

        $decision = $state->decide([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                $left,
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);

        $this->assertSame(CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH, $decision->reason);
        $this->assertSame('.', $decision->mismatchFieldPath);
        $this->assertSame('different', $decision->mismatchRelation);
        $encoded = json_encode($decision->toLogContext(), \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('totally_custom_blob', $encoded);
        $this->assertStringNotContainsString('secret-left', $encoded);
        $this->assertStringNotContainsString('secret-right', $encoded);
    }

    public function testDeeplyNestedMismatchPathsStayBoundedWithoutIndexesOrSecrets(): void
    {
        $deepLeft = [
            'type' => 'reasoning',
            'summary' => [[
                'type' => 'summary_text',
                'text' => [
                    ['type' => 'text', 'text' => ['type' => 'secret_left_nested']],
                ],
            ]],
        ];
        $deepRight = [
            'type' => 'reasoning',
            'summary' => [[
                'type' => 'summary_text',
                'text' => [
                    ['type' => 'text', 'text' => ['type' => 'secret_right_nested']],
                ],
            ]],
        ];

        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['model' => 'gpt-5.6-luna', 'input' => [['role' => 'user', 'content' => 'first']], 'stream' => true],
            'resp_deep_bound',
            [$deepRight],
        );

        $decision = $state->decide([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                $deepLeft,
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);

        $this->assertSame(CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH, $decision->reason);
        $this->assertNotNull($decision->mismatchFieldPath);
        $this->assertLessThanOrEqual(64, \strlen((string) $decision->mismatchFieldPath));
        $this->assertDoesNotMatchRegularExpression('/\\.\\d+(\\.|$)/', (string) $decision->mismatchFieldPath);
        $this->assertSame('different', $decision->mismatchRelation);

        $encoded = json_encode($decision->toLogContext(), \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret_left_nested', $encoded);
        $this->assertStringNotContainsString('secret_right_nested', $encoded);
        $this->assertStringNotContainsString('.0.', $encoded);
    }

    public function testKeepsHistoricalConfigurationUpdateInPrefixAndDeltasOnlySuffix(): void
    {
        $baselineBody = [
            'model' => 'gpt-6-astra',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'second'],
            ],
            'stream' => true,
        ];
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $baselineBody,
            'resp_123',
            [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
        );

        $current = [
            'model' => 'gpt-6-astra',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'second'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']],
                ['role' => 'user', 'content' => 'third'],
            ],
            'stream' => true,
        ];

        $delta = $state->decide($current)->delta;
        $this->assertNotNull($delta);
        $this->assertSame('resp_123', $delta['previous_response_id']);
        $this->assertSame([
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']],
            ['role' => 'user', 'content' => 'third'],
        ], $delta['input']);
    }

    public function testPendingToolOutputsLocateRelativeToDeltaBoundaryWithoutLoggingIds(): void
    {
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            [
                'model' => 'gpt-5.6-luna',
                'input' => [['role' => 'user', 'content' => 'first']],
                'stream' => true,
            ],
            'resp_pending_tools',
            [[
                'type' => 'function_call',
                'id' => 'fc_pending',
                'call_id' => 'call_pending',
                'name' => 'read',
                'arguments' => '{}',
            ]],
        );

        $decision = $state->decide([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                [
                    'type' => 'function_call',
                    'id' => 'fc_pending',
                    'call_id' => 'call_pending',
                    'name' => 'read',
                    'arguments' => '{}',
                ],
                [
                    'type' => 'function_call_output',
                    'call_id' => 'call_pending',
                    'output' => 'secret-tool-output',
                ],
            ],
            'stream' => true,
        ]);

        $this->assertSame(CodexWebSocketContinuationDecision::REASON_DELTA, $decision->reason);
        $this->assertSame(1, $decision->pendingFunctionCallCount);
        $this->assertSame(0, $decision->pendingOutputsInPrefixCount);
        $this->assertSame(1, $decision->pendingOutputsInDeltaCount);
        $this->assertSame(0, $decision->pendingOutputsMissingCount);
        $this->assertSame([['type' => 'function_call_output', 'call_id' => 'call_pending', 'output' => 'secret-tool-output']], $decision->delta['input'] ?? null);

        $missing = $state->decide([
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                [
                    'type' => 'function_call',
                    'id' => 'fc_pending',
                    'call_id' => 'call_pending',
                    'name' => 'read',
                    'arguments' => '{}',
                ],
                ['role' => 'user', 'content' => 'next'],
            ],
            'stream' => true,
        ]);
        $this->assertSame(CodexWebSocketContinuationDecision::REASON_DELTA, $missing->reason);
        $this->assertSame(1, $missing->pendingFunctionCallCount);
        $this->assertSame(0, $missing->pendingOutputsInPrefixCount);
        $this->assertSame(0, $missing->pendingOutputsInDeltaCount);
        $this->assertSame(1, $missing->pendingOutputsMissingCount);

        $encoded = json_encode($decision->toLogContext(), \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('call_pending', $encoded);
        $this->assertStringNotContainsString('fc_pending', $encoded);
        $this->assertStringNotContainsString('secret-tool-output', $encoded);
    }
}
