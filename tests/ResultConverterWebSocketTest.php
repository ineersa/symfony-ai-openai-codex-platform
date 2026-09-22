<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketResultHandle;
use Symfony\AI\Platform\Bridge\OpenAICodex\ResultConverter;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;

final class ResultConverterWebSocketTest extends TestCase
{
    public function testWebSocketEventStreamUsesExistingMapper(): void
    {
        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'hello'],
            ['type' => 'response.completed', 'response' => ['output' => []]],
        ];
        $raw = new InMemoryRawResult([], $events, new CodexWebSocketResultHandle());

        $converter = new ResultConverter();
        $result = $converter->convert($raw, ['stream' => true]);

        $this->assertInstanceOf(StreamResult::class, $result);
        $chunks = iterator_to_array($result->getContent());
        $this->assertInstanceOf(TextDelta::class, $chunks[0]);
        $this->assertSame('hello', $chunks[0]->getText());
    }
}
