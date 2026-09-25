<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexSseStream;
use Symfony\AI\Platform\Bridge\OpenAICodex\ResultConverter;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AllowMockObjectsWithoutExpectations]
final class ResultConverterTest extends TestCase
{
    public function testConvertTextResult(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Hello world',
                    ]],
                ],
            ],
        ]);

        $result = $converter->convert(new RawHttpResult($httpResponse));

        $this->assertInstanceOf(TextResult::class, $result);
        $this->assertSame('Hello world', $result->getContent());
    }

    public function testConvertToolCallResult(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'output' => [
                [
                    'type' => 'function_call',
                    'id' => 'call_123',
                    'name' => 'test_function',
                    'arguments' => '{"arg1": "value1"}',
                ],
            ],
        ]);

        $result = $converter->convert(new RawHttpResult($httpResponse));

        $this->assertInstanceOf(ToolCallResult::class, $result);
        $toolCalls = $result->getContent();
        $this->assertCount(1, $toolCalls);
        $this->assertSame('call_123', $toolCalls[0]->getId());
        $this->assertSame('test_function', $toolCalls[0]->getName());
        $this->assertSame(['arg1' => 'value1'], $toolCalls[0]->getArguments());
    }

    public function testConvertToolCallResultJoinsDistinctCallAndItemIds(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'output' => [
                [
                    'type' => 'function_call',
                    'id' => 'fc_item',
                    'call_id' => 'call_item',
                    'name' => 'test_function',
                    'arguments' => '{"arg1": "value1"}',
                ],
            ],
        ]);

        $result = $converter->convert(new RawHttpResult($httpResponse));
        $toolCalls = $result->getContent();
        $this->assertSame('call_item|fc_item', $toolCalls[0]->getId());
    }

    public function testConvertMultipleMessagesIntoMultiPartResult(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'output' => [
                [
                    'role' => 'assistant',
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Part 1',
                    ]],
                ],
                [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Part 2',
                    ]],
                    'type' => 'message',
                ],
            ],
        ]);

        $result = $converter->convert(new RawHttpResult($httpResponse));

        $this->assertInstanceOf(MultiPartResult::class, $result);
        $output = $result->getContent();
        $this->assertCount(2, $output);
        $this->assertSame('Part 1', $output[0]->getContent());
        $this->assertSame('Part 2', $output[1]->getContent());
    }

    public function testConvertReasoningPlusMessageIntoMultiPartResult(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'output' => [
                [
                    'type' => 'reasoning',
                    'id' => 'rs_1',
                    'summary' => [
                        ['type' => 'summary_text', 'text' => 'Let me work through this.'],
                    ],
                ],
                [
                    'type' => 'message',
                    'id' => 'msg_1',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '{"answer": 42}',
                    ]],
                ],
            ],
        ]);

        $result = $converter->convert(new RawHttpResult($httpResponse));

        $this->assertInstanceOf(MultiPartResult::class, $result);
        $parts = $result->getContent();
        $this->assertCount(2, $parts);
        $this->assertInstanceOf(ThinkingResult::class, $parts[0]);
        $this->assertSame('Let me work through this.', $parts[0]->getContent());
        $this->assertInstanceOf(TextResult::class, $parts[1]);
        $this->assertSame('{"answer": 42}', $parts[1]->getContent());
    }

    public function testConvertReasoningEmitsOneThinkingResultPerSummaryChunk(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'output' => [
                [
                    'type' => 'reasoning',
                    'id' => 'rs_1',
                    'summary' => [
                        ['type' => 'summary_text', 'text' => 'First, I subtract 7.'],
                        ['type' => 'summary_text', 'text' => 'Then I divide by 8.'],
                    ],
                ],
                [
                    'type' => 'message',
                    'id' => 'msg_1',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'x = -3.75',
                    ]],
                ],
            ],
        ]);

        $result = $converter->convert(new RawHttpResult($httpResponse));

        $this->assertInstanceOf(MultiPartResult::class, $result);
        $parts = $result->getContent();
        $this->assertCount(3, $parts);
        $this->assertInstanceOf(ThinkingResult::class, $parts[0]);
        $this->assertSame('First, I subtract 7.', $parts[0]->getContent());
        $this->assertInstanceOf(ThinkingResult::class, $parts[1]);
        $this->assertSame('Then I divide by 8.', $parts[1]->getContent());
        $this->assertInstanceOf(TextResult::class, $parts[2]);
        $this->assertSame('x = -3.75', $parts[2]->getContent());
    }

    public function testConvertReasoningWithoutSummaryIsDropped(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'output' => [
                [
                    'type' => 'reasoning',
                    'id' => 'rs_1',
                    'summary' => [],
                ],
                [
                    'type' => 'message',
                    'id' => 'msg_1',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'final',
                    ]],
                ],
            ],
        ]);

        $result = $converter->convert(new RawHttpResult($httpResponse));

        $this->assertInstanceOf(TextResult::class, $result);
        $this->assertSame('final', $result->getContent());
    }

    public function testConvertRefusalResult(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'refusal',
                        'refusal' => 'I cannot help with that request.',
                    ]],
                ],
            ],
        ]);

        $result = $converter->convert(new RawHttpResult($httpResponse));

        $this->assertInstanceOf(TextResult::class, $result);
        $this->assertStringContainsString('refused to generate', $result->getContent());
        $this->assertStringContainsString('I cannot help with that request.', $result->getContent());
    }

    public function testContentFilterException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $httpResponse->expects($this->exactly(1))
            ->method('toArray')
            ->willReturnCallback(static function ($throw = true) {
                if ($throw) {
                    throw new class extends \Exception implements ClientExceptionInterface {
                        public function getResponse(): ResponseInterface
                        {
                            throw new \RuntimeException('Not implemented');
                        }
                    };
                }

                return [
                    'error' => [
                        'code' => 'content_filter',
                        'message' => 'Content was filtered',
                    ],
                ];
            });

        $this->expectException(ContentFilterException::class);
        $this->expectExceptionMessage('Content was filtered');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsAuthenticationExceptionOnInvalidApiKey(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(401);
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => [
                'message' => 'Invalid API key provided',
            ],
        ]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key provided');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsExceptionWhenNoOutput(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Response does not contain output');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsBadRequestExceptionOnBadRequestResponse(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(400);
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => [
                'message' => 'Bad Request: invalid parameters',
            ],
        ]));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Bad Request: invalid parameters');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsBadRequestExceptionOnBadRequestResponseWithNoResponseBody(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(400);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Bad Request');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsRateLimitExceededExceptionOn429(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(429);
        $httpResponse->method('getContent')->willReturn('{"error":{"message":"You exceeded your current quota, please check your plan and billing details."}}');

        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessage('Rate limit exceeded. You exceeded your current quota, please check your plan and billing details.');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsDetailedErrorException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $httpResponse->method('toArray')->willReturn([
            'error' => [
                'code' => 'invalid_request_error',
                'type' => 'invalid_request',
                'param' => 'model',
                'message' => 'The model `unknown` does not exist',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[invalid_request_error/invalid_request/model]');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testStreamTransmitsUsageToResultMetadata(): void
    {
        $converter = new ResultConverter();

        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            [
                'type' => 'message.delta.output_text.delta',
                'delta' => 'Hello',
            ],
            [
                'type' => 'message.delta.output_text.delta',
                'delta' => ' world',
            ],
            [
                'type' => 'response.completed',
                'response' => [
                    'usage' => [
                        'input_tokens' => 11,
                        'output_tokens' => 7,
                        'output_tokens_details' => [
                            'reasoning_tokens' => 2,
                        ],
                        'input_tokens_details' => [
                            'cached_tokens' => 3,
                        ],
                        'total_tokens' => 18,
                    ],
                    'output' => [],
                ],
            ],
        ];

        $raw = new class($httpResponse, $events) implements RawResultInterface {
            /**
             * @param array<array<string, mixed>> $events
             */
            public function __construct(
                private readonly ResponseInterface $response,
                private readonly array $events,
            ) {
            }

            public function getData(): array
            {
                return [];
            }

            public function getDataStream(): iterable
            {
                foreach ($this->events as $event) {
                    yield $event;
                }
            }

            public function getObject(): object
            {
                return $this->response;
            }
        };

        $streamResult = $converter->convert($raw, ['stream' => true]);

        $this->assertInstanceOf(StreamResult::class, $streamResult);

        $chunks = [];
        foreach ($streamResult->getContent() as $part) {
            $chunks[] = $part;
        }

        $this->assertInstanceOf(TextDelta::class, $chunks[0]);
        $this->assertSame('Hello', $chunks[0]->getText());
        $this->assertInstanceOf(TextDelta::class, $chunks[1]);
        $this->assertSame(' world', $chunks[1]->getText());

        $this->assertInstanceOf(TokenUsage::class, $chunks[2]);
        $this->assertSame(11, $chunks[2]->getPromptTokens());
        $this->assertSame(7, $chunks[2]->getCompletionTokens());
        $this->assertSame(2, $chunks[2]->getThinkingTokens());
        $this->assertSame(3, $chunks[2]->getCachedTokens());
        $this->assertSame(18, $chunks[2]->getTotalTokens());
    }

    public function testStreamWithToolCalls(): void
    {
        $converter = new ResultConverter();

        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            [
                'type' => 'response.completed',
                'response' => [
                    'output' => [
                        [
                            'type' => 'function_call',
                            'id' => 'call_456',
                            'name' => 'get_weather',
                            'arguments' => '{"city": "Berlin"}',
                        ],
                    ],
                ],
            ],
        ];

        $raw = new class($httpResponse, $events) implements RawResultInterface {
            /**
             * @param array<array<string, mixed>> $events
             */
            public function __construct(
                private readonly ResponseInterface $response,
                private readonly array $events,
            ) {
            }

            public function getData(): array
            {
                return [];
            }

            public function getDataStream(): iterable
            {
                foreach ($this->events as $event) {
                    yield $event;
                }
            }

            public function getObject(): object
            {
                return $this->response;
            }
        };

        $streamResult = $converter->convert($raw, ['stream' => true]);

        $this->assertInstanceOf(StreamResult::class, $streamResult);

        $chunks = [];
        foreach ($streamResult->getContent() as $part) {
            $chunks[] = $part;
        }

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(ToolCallComplete::class, $chunks[0]);
        $toolCalls = $chunks[0]->getToolCalls();
        $this->assertCount(1, $toolCalls);
        $this->assertSame('call_456', $toolCalls[0]->getId());
        $this->assertSame('get_weather', $toolCalls[0]->getName());
        $this->assertSame(['city' => 'Berlin'], $toolCalls[0]->getArguments());
    }

    public function testCompletedStreamRetainsZeroArgumentToolCallForReplay(): void
    {
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);
        $events = [
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'function_call',
                'id' => 'fc_zero',
                'call_id' => 'call_zero',
                'name' => 'task_list',
                'arguments' => '{}',
            ]],
            ['type' => 'response.completed', 'response' => ['output' => []]],
        ];
        $result = (new ResultConverter())->convert(new InMemoryRawResult([], $events, $httpResponse), ['stream' => true]);
        $this->assertInstanceOf(StreamResult::class, $result);

        $chunks = iterator_to_array($result->getContent());
        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(ToolCallComplete::class, $chunks[0]);
        $call = $chunks[0]->getToolCalls()[0];
        $this->assertSame('call_zero|fc_zero', $call->getId());
        $this->assertSame('task_list', $call->getName());
        $this->assertSame([], $call->getArguments());
    }

    public function testStreamWithReasoningContent(): void
    {
        $converter = new ResultConverter();

        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            [
                'type' => 'response.reasoning_summary_text.delta',
                'delta' => 'Let me think',
            ],
            [
                'type' => 'response.reasoning_summary_text.delta',
                'delta' => ' about this...',
            ],
            [
                'type' => 'response.reasoning_summary_text.done',
            ],
            [
                'type' => 'response.output_item.done',
                'item' => [
                    'type' => 'reasoning',
                    'id' => 'rs_1',
                    'summary' => [['type' => 'summary_text', 'text' => 'Let me think about this...']],
                    'status' => 'completed',
                ],
            ],
            [
                'type' => 'response.output_text.delta',
                'delta' => 'The answer is 42.',
            ],
            [
                'type' => 'response.completed',
                'response' => [
                    'output' => [],
                ],
            ],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $streamResult = $converter->convert($raw, ['stream' => true]);

        $this->assertInstanceOf(StreamResult::class, $streamResult);

        $chunks = iterator_to_array($streamResult->getContent());

        // ThinkingStart, ThinkingDelta ×2, ThinkingComplete (from output_item.done),
        // then TextDelta.
        $this->assertCount(5, $chunks);
        $this->assertInstanceOf(ThinkingStart::class, $chunks[0]);
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[1]);
        $this->assertSame('Let me think', $chunks[1]->getThinking());
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[2]);
        $this->assertSame(' about this...', $chunks[2]->getThinking());
        $this->assertInstanceOf(ThinkingComplete::class, $chunks[3]);
        $this->assertSame('Let me think about this...', $chunks[3]->getThinking());
        // Signature is the full item JSON (no encrypted_content in this fixture).
        $this->assertNotNull($chunks[3]->getSignature());
        $this->assertInstanceOf(TextDelta::class, $chunks[4]);
        $this->assertSame('The answer is 42.', $chunks[4]->getText());
    }

    public function testThrowsBadRequestWithCodeTypeParamOnStructuredError(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(400);
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => [
                'code' => 'invalid_request_error',
                'type' => 'invalid_request',
                'param' => 'model',
                'message' => 'The model `unknown` does not exist',
            ],
        ]));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('[invalid_request_error/invalid_request/model]: The model `unknown` does not exist');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsBadRequestWithBodyPreviewOnNonJsonResponse(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(400);
        $httpResponse->method('getContent')->willReturn('<html><body>Bad Request: invalid parameters</body></html>');
        $httpResponse->method('getHeaders')->willReturn(['content-type' => ['text/html; charset=utf-8']]);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('text/html; charset=utf-8');
        $this->expectExceptionMessage('Bad Request: invalid parameters');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsBadRequestWithAlternativeTopLevelErrorKeys(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(400);
        // Hydra/OAuth-style error with error_description instead of error.message
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => 'invalid_request',
            'error_description' => 'The authorization code is invalid or expired',
        ]));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('The authorization code is invalid or expired');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsBadRequestWithEmptyBodyFallsBackToClientError(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(400);
        // getContent returns empty string, no getHeaders mock (returns empty)
        $httpResponse->method('getContent')->willReturn('');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Bad Request');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsAuthenticationWithCodeTypeOnStructuredError(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(401);
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => [
                'code' => 'invalid_api_key',
                'message' => 'Invalid API key provided',
            ],
        ]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('[invalid_api_key]: Invalid API key provided');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testThrowsRateLimitWithCodeTypeOnStructuredError(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(429);
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => [
                'code' => 'rate_limited',
                'type' => 'rate_limit_error',
                'message' => 'Too many requests, please retry after 60 seconds',
            ],
        ]));

        $this->expectException(RateLimitExceededException::class);
        // RateLimitExceededException prepends "Rate limit exceeded. "
        $this->expectExceptionMessage('Rate limit exceeded. [rate_limited/rate_limit_error]: Too many requests, please retry after 60 seconds');

        $converter->convert(new RawHttpResult($httpResponse));
    }

    public function testStreamNon2xxThrowsBeforeSseIteration(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(404);
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => [
                'code' => 'not_found',
                'type' => 'resource_missing',
                'message' => 'The requested resource was not found',
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404: [not_found/resource_missing]: The requested resource was not found');

        $converter->convert(new RawHttpResult($httpResponse, new CodexSseStream()), ['stream' => true]);
    }

    public function testStreamServerErrorRetainsStructuredProviderDiagnostic(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createMock(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(500);
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => [
                'code' => 'server_error',
                'type' => 'api_error',
                'param' => 'model',
                'message' => 'deployment unavailable',
            ],
        ]));

        $raw = new RawHttpResult($httpResponse, new CodexSseStream());

        try {
            $converter->convert($raw, ['stream' => true]);
            $this->fail('Expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame(
                'Server error (HTTP 500). [server_error/api_error/model]: deployment unavailable',
                $e->getMessage(),
            );
        }
    }

    public function testStreamErrorMessageRetainsBoundedProviderMessageButOmitsOtherFreeText(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $secret = 'LEAKED_STREAM_SECRET_MARKER_7f3a91c2';
        $descriptionMarker = 'LEAKED_DESCRIPTION_MARKER_7f3a91c2';
        $events = [
            ['type' => 'error', 'error' => [
                'code' => 'invalid_request_error',
                'type' => 'invalid_request',
                'param' => 'model',
                'message' => $secret,
                'error_description' => $descriptionMarker,
                'detail' => $descriptionMarker,
            ]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        try {
            $streamResult = $converter->convert($raw, ['stream' => true]);
            iterator_to_array($streamResult->getContent());
            $this->fail('Expected stream error');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[invalid_request_error/invalid_request/model]', $e->getMessage());
            // The bounded provider error.message is retained so an invalid
            // request keeps its actionable diagnostic; alternative free-text
            // keys (error_description, detail) never leak.
            $this->assertStringContainsString($secret, $e->getMessage());
            $this->assertStringNotContainsString($descriptionMarker, $e->getMessage());
        }
    }

    // -- Stream error handling (regression: silent mid-turn death) --

    public function testStreamServerErrorEventThrowsTypedException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $secret = 'LEAKED_STREAM_OVERLOAD_SECRET_MARKER_4b91e2d7';
        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Partial'],
            ['type' => 'error', 'error' => [
                'code' => 'server_error',
                'type' => 'server_error',
                'message' => $secret,
            ]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        try {
            $streamResult = $converter->convert($raw, ['stream' => true]);
            iterator_to_array($streamResult->getContent());
            $this->fail('Expected stream error');
        } catch (ServerException $e) {
            $this->assertNull($e->getStatusCode());
            $this->assertStringContainsString('[server_error/server_error]', $e->getMessage());
            // Bounded provider message is retained for diagnostics.
            $this->assertStringContainsString($secret, $e->getMessage());
        }
    }

    /**
     * Regression test: 'response.failed' events during streaming must throw
     * immediately.  Previously these events were silently dropped, producing
     * partial thinking as if the turn completed normally.
     */
    public function testStreamResponseFailedThrowsRuntimeException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'I think'],
            ['type' => 'response.failed', 'response' => [
                'error' => ['code' => 'rate_limited', 'message' => 'Rate limited'],
            ]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[rate_limited]');

        $streamResult = $converter->convert($raw, ['stream' => true]);
        iterator_to_array($streamResult->getContent());
    }

    /**
     * 'response.incomplete' events (context limit, etc.) must throw with
     * the reason. Partial tool calls are intentionally NOT yielded — the
     * RuntimeException propagates to LlmPlatformAdapter::consumeStream →
     * errorResult (stopReason='error') which is short-circuited by
     * LlmStepResultHandler::__invoke (checks error !== null, returns
     * RunStatus::Failed with empty pendingToolCalls) before any tool
     * dispatch, so yielding partial tool calls would be dead code.
     */
    public function testStreamResponseIncompleteThrowsRuntimeException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Partial'],
            ['type' => 'response.incomplete', 'response' => [
                'incomplete_details' => ['reason' => 'max_tokens'],
                'output' => [],
            ]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/incomplete.*max_tokens/');

        $streamResult = $converter->convert($raw, ['stream' => true]);
        iterator_to_array($streamResult->getContent());
    }

    /**
     * A stream that produces events but never emits response.completed
     * (or response.done) must throw IncompleteStreamException so the caller
     * knows the response is truncated, not completed.
     */
    public function testStreamWithoutResponseCompletedThrowsIncompleteStreamException(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
            // No response.completed — stream just ends
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $this->expectException(IncompleteStreamException::class);
        $this->expectExceptionMessageMatches('/ended before response\.completed/');

        $streamResult = $converter->convert($raw, ['stream' => true]);
        iterator_to_array($streamResult->getContent());
    }

    /**
     * A thinking-only stream (reasoning items, no output_text) must capture
     * the full reasoning item JSON as the thinking signature so it survives
     * persistence and round-trips on the next turn.
     *
     * Reasoning signature must be captured at response.output_item.done — the
     * only event that carries encrypted_content.  The added item does NOT carry
     * it, and reasoning_summary_text.done fires before encrypted_content is
     * available.  This matches pi-mono openai-responses-shared.ts:443-452
     * (atomic capture + emit at item.done).
     */
    public function testStreamThinkingOnlyCapturesReasoningSignature(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        // Real event order: added (no encrypted_content) → summary_part.added
        // → summary_text.delta ×N → summary_text.done → summary_part.done
        // → output_item.done (WITH encrypted_content).
        $events = [
            // Added item: NO encrypted_content (it starts in-progress).
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'status' => 'in_progress',
            ]],
            ['type' => 'response.reasoning_summary_part.added', 'part' => [
                'type' => 'summary_text',
                'text' => '',
            ]],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me think'],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => ' about it'],
            ['type' => 'response.reasoning_summary_text.done'],
            ['type' => 'response.reasoning_summary_part.done'],
            // Done item: WITH encrypted_content (the authoritative capture point).
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'encrypted_content' => 'enc_abc123',
                'summary' => [['type' => 'summary_text', 'text' => 'Let me think about it']],
                'status' => 'completed',
            ]],
            ['type' => 'response.completed', 'response' => ['output' => []]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $streamResult = $converter->convert($raw, ['stream' => true]);
        $this->assertInstanceOf(StreamResult::class, $streamResult);

        $chunks = iterator_to_array($streamResult->getContent());

        // ThinkingStart, ThinkingDelta x2, then ThinkingComplete emitted from
        // output_item.done (NOT from summary_text.done).
        $this->assertInstanceOf(ThinkingStart::class, $chunks[0]);
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[1]);
        $this->assertSame('Let me think', $chunks[1]->getThinking());
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[2]);
        $this->assertSame(' about it', $chunks[2]->getThinking());
        $this->assertInstanceOf(ThinkingComplete::class, $chunks[3]);
        $this->assertSame('Let me think about it', $chunks[3]->getThinking());

        // The signature must be present, round-trip through JSON, and contain
        // encrypted_content (the key the Codex API requires on the next turn).
        $this->assertNotNull($chunks[3]->getSignature());
        $decoded = json_decode($chunks[3]->getSignature(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('reasoning', $decoded['type']);
        $this->assertSame('rs_1', $decoded['id']);
        $this->assertSame('enc_abc123', $decoded['encrypted_content']);
        // Prove the completed item has encrypted_content (added item does not).
        $this->assertArrayHasKey('encrypted_content', $decoded);
    }

    /**
     * Codex streams distinct reasoning summary parts without embedded newlines.
     * Ignoring response.reasoning_summary_part.added glues adjacent bold markers
     * into "****" (live: "**Planning...fork****Evaluating...edits**").
     *
     * Thesis: two summary parts with multi-token first part yield one synthetic
     * newline at the semantic boundary; intra-part token chunks stay concatenated.
     */
    public function testStreamInsertsNewlineBetweenReasoningSummaryParts(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'status' => 'in_progress',
            ]],
            ['type' => 'response.reasoning_summary_part.added', 'part' => [
                'type' => 'summary_text',
                'text' => '',
            ]],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => '**Planning user-level commit correction via '],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'fork**'],
            ['type' => 'response.reasoning_summary_text.done'],
            ['type' => 'response.reasoning_summary_part.done'],
            ['type' => 'response.reasoning_summary_part.added', 'part' => [
                'type' => 'summary_text',
                'text' => '',
            ]],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => '**Evaluating fork approach for home directory edits**'],
            ['type' => 'response.reasoning_summary_text.done'],
            ['type' => 'response.reasoning_summary_part.done'],
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'summary' => [
                    ['type' => 'summary_text', 'text' => '**Planning user-level commit correction via fork**'],
                    ['type' => 'summary_text', 'text' => '**Evaluating fork approach for home directory edits**'],
                ],
                'status' => 'completed',
            ]],
            ['type' => 'response.completed', 'response' => ['output' => []]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);
        $streamResult = $converter->convert($raw, ['stream' => true]);
        $this->assertInstanceOf(StreamResult::class, $streamResult);

        $chunks = iterator_to_array($streamResult->getContent());

        $this->assertInstanceOf(ThinkingStart::class, $chunks[0]);
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[1]);
        $this->assertSame('**Planning user-level commit correction via ', $chunks[1]->getThinking());
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[2]);
        $this->assertSame('fork**', $chunks[2]->getThinking());
        // Semantic part boundary — not between ordinary token deltas.
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[3]);
        $this->assertSame("\n", $chunks[3]->getThinking());
        $this->assertInstanceOf(ThinkingDelta::class, $chunks[4]);
        $this->assertSame('**Evaluating fork approach for home directory edits**', $chunks[4]->getThinking());
        $this->assertInstanceOf(ThinkingComplete::class, $chunks[5]);
        $this->assertSame(
            "**Planning user-level commit correction via fork**\n**Evaluating fork approach for home directory edits**",
            $chunks[5]->getThinking(),
        );
        $this->assertStringNotContainsString('****', $chunks[5]->getThinking());
    }

    /**
     * 'response.done' is used by some Codex API versions instead of
     * 'response.completed'.  It must be normalized so downstream consumers
     * (usage extraction, tool call emission) treat them identically.
     */
    public function testStreamResponseDoneNormalizedToCompleted(): void
    {
        $converter = new ResultConverter();
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(200);

        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
            ['type' => 'response.done', 'response' => ['output' => []]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);

        $streamResult = $converter->convert($raw, ['stream' => true]);
        $this->assertInstanceOf(StreamResult::class, $streamResult);

        $chunks = iterator_to_array($streamResult->getContent());
        $this->assertInstanceOf(TextDelta::class, $chunks[0]);
        $this->assertSame('Hello', $chunks[0]->getText());
        // No exception — response.done was normalized to response.completed
    }
}
