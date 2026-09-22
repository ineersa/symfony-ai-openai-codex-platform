<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Result\Stream\HttpStreamInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Codex-specific SSE stream parser.
 *
 * Unlike the default SseStream, this implementation does NOT depend on
 * Symfony's EventSourceHttpClient content-type detection, because the
 * Codex backend may not return the standard text/event-stream Content-Type
 * header. Instead, it reads the full response body, splits by SSE event
 * boundaries (\n\n), extracts data: lines, and yields decoded JSON.
 *
 * This trades true streaming for reliability — the full body is buffered
 * in memory. For the Codex Responses API (single-response-per-request
 * with tool calls in one go, not token-by-token), this is acceptable.
 */
final class CodexSseStream implements HttpStreamInterface
{
    /**
     * Maximum bytes to buffer for SSE parsing.
     * 8 MB is generous; Codex response bodies are typically <100 KB.
     */
    private const int MAX_BODY_BYTES = 8_388_608;

    public function stream(ResponseInterface $response): iterable
    {
        // Read the full response body.
        $body = $response->getContent(false);

        if (\strlen($body) > self::MAX_BODY_BYTES) {
            throw new \RuntimeException(\sprintf('Response body exceeds maximum allowed size of %d bytes for Codex SSE parsing.', self::MAX_BODY_BYTES));
        }

        // Split by SSE event boundaries (double newline).
        $events = preg_split("/(?:\r\n){2,}|\r{2,}|\n{2,}/", $body);

        foreach ($events as $eventBlock) {
            $eventBlock = trim($eventBlock);
            if ('' === $eventBlock) {
                continue;
            }

            $json = $this->extractEventData($eventBlock);
            if (null !== $json) {
                try {
                    yield json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \RuntimeException(\sprintf('Failed to decode SSE data for Codex response: %s', $e->getMessage()), previous: $e);
                }
            }
        }
    }

    /**
     * Extract the JSON data from a single SSE event block.
     *
     * An SSE event block looks like:
     *   event: response.created
     *   data: {"type":"response.created",...}
     *
     * Returns only the complete data: value, or null if no data field is found
     * or the event is a comment or [DONE] sentinel.
     */
    private function extractEventData(string $eventBlock): ?string
    {
        $eventData = '';

        foreach (preg_split("/(?:\r\n|[\r\n])/", $eventBlock) as $line) {
            $line = trim($line);
            if ('' === $line || ':' === ($line[0] ?? '') || str_starts_with($line, ':')) {
                // Comment or empty line — skip
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                $eventData .= substr($line, 6);
            }
        }

        if ('' === $eventData || '[DONE]' === $eventData) {
            return null;
        }

        return $eventData;
    }
}
