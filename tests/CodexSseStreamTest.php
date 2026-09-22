<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexSseStream;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CodexSseStreamTest extends TestCase
{
    public function testParsesSingleSseEvent(): void
    {
        $body = "event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\",\"status\":\"in_progress\"}}\n\n";
        $events = iterator_to_array($this->streamResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame('response.created', $events[0]['type']);
        $this->assertSame('resp_1', $events[0]['response']['id']);
    }

    public function testParsesMultipleSseEvents(): void
    {
        $body = "event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\"}}\n\n"
            ."event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Hello\"}\n\n"
            ."event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\",\"status\":\"completed\"}}\n\n";

        $events = iterator_to_array($this->streamResponse($body));

        $this->assertCount(3, $events);
        $this->assertSame('response.created', $events[0]['type']);
        $this->assertSame('response.output_text.delta', $events[1]['type']);
        $this->assertSame('Hello', $events[1]['delta']);
        $this->assertSame('response.completed', $events[2]['type']);
    }

    public function testSkipsEventWithDoneSentinel(): void
    {
        $body = "event: response.created\ndata: {\"type\":\"response.created\"}\n\n"
            ."event: done\ndata: [DONE]\n\n";

        $events = iterator_to_array($this->streamResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame('response.created', $events[0]['type']);
    }

    public function testHandlesCrLfNewlines(): void
    {
        $body = "event: response.created\r\ndata: {\"type\":\"response.created\"}\r\n\r\n";

        $events = iterator_to_array($this->streamResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame('response.created', $events[0]['type']);
    }

    public function testHandlesEmptyBody(): void
    {
        $events = iterator_to_array($this->streamResponse(''));

        $this->assertCount(0, $events);
    }

    public function testHandlesBodyWithOnlyComments(): void
    {
        $body = ": comment line\n: another comment\n\n";

        $events = iterator_to_array($this->streamResponse($body));

        $this->assertCount(0, $events);
    }

    public function testThrowsOnInvalidJsonInData(): void
    {
        $body = "event: response.created\ndata: {invalid json}\n\n";

        $gen = $this->streamResponse($body);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to decode SSE data/');

        iterator_to_array($gen);
    }

    public function testThrowsOnBodyExceedingMaxSize(): void
    {
        // Create a body > 8 MB (each event ~38 bytes, 250k = ~9.5 MB)
        $body = str_repeat("event: test\ndata: {\"type\":\"test\"}\n\n", 250000);

        $gen = $this->streamResponse($body);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/maximum allowed size/');

        iterator_to_array($gen);
    }

    public function testHandlesResponseWithToolCallEvent(): void
    {
        $body = "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\",\"status\":\"completed\",\"output\":[{\"type\":\"function_call\",\"id\":\"call_123\",\"name\":\"get_weather\",\"arguments\":\"{\\\"city\\\":\\\"Berlin\\\"}\"}]}}\n\n";

        $events = iterator_to_array($this->streamResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame('response.completed', $events[0]['type']);
        $this->assertSame('call_123', $events[0]['response']['output'][0]['id']);
    }

    /**
     * @param array<string, mixed> $responseHeaders
     */
    private function streamResponse(string $body, array $responseHeaders = []): \Generator
    {
        $httpClient = new MockHttpClient([new MockResponse($body, ['http_code' => 200] + $responseHeaders)]);
        $response = $httpClient->request('GET', 'https://example.com/test');
        $stream = new CodexSseStream();

        return $stream->stream($response);
    }
}
