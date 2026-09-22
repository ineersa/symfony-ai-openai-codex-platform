<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAICodex\Error\ProviderErrorFormatter;
use Symfony\AI\Platform\Bridge\OpenAICodex\Result\CancellableRawResultInterface;

/**
 * Codex WebSocket raw result: JSON event stream without an HTTP response envelope.
 */
final class RawWebSocketResult implements CancellableRawResultInterface
{
    private readonly CodexWebSocketResultHandle $handle;

    private bool $connectionClosed = false;

    private readonly LoggerInterface $logger;

    private bool $retainConnection = false;

    private bool $cacheFinalized = false;

    /**
     * Completed output items observed via response.output_item.done.
     *
     * Used as the continuation baseline when the terminal response omits or
     * empties response.output. Mirrors ResultConverter's stream fallback for
     * function calls so previous_response_id deltas do not re-send items the
     * provider already owns.
     *
     * @var list<array<string, mixed>>
     */
    private array $completedOutputItems = [];

    public function __construct(
        private readonly WebsocketConnection $connection,
        private readonly float $idleTimeoutSeconds,
        ?LoggerInterface $logger = null,
        private bool $aborted = false,
        private readonly ?CodexWebSocketCachedStreamContext $cachedStreamContext = null,
    ) {
        $this->handle = new CodexWebSocketResultHandle();
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
        if (!$this->cacheFinalized) {
            $this->finalizeCachedLifecycle(false);
        }
        $this->closeConnection();
    }

    public function getData(): array
    {
        throw new \RuntimeException('Codex WebSocket results are streaming-only.');
    }

    public function getDataStream(): iterable
    {
        if ($this->aborted) {
            return;
        }

        $streamSucceeded = false;
        try {
            yield from $this->iterateEvents($streamSucceeded);
        } catch (\Throwable $e) {
            $this->finalizeCachedLifecycle(false);
            $this->closeConnection();
            throw $e;
        } finally {
            if ($streamSucceeded) {
                $this->finalizeCachedLifecycle(true);
            } else {
                $this->finalizeCachedLifecycle(false);
                $this->closeConnection();
            }
        }
    }

    public function getObject(): object
    {
        return $this->handle;
    }

    public function abort(): void
    {
        $this->aborted = true;
        $this->finalizeCachedLifecycle(false);
        $this->closeConnection();
    }

    /**
     * @return \Generator<int, array<string, mixed>, mixed, void>
     *
     * @param-out bool $streamSucceeded
     */
    private function iterateEvents(bool &$streamSucceeded): \Generator
    {
        while (!$this->aborted) {
            try {
                // receive() is cancellable; idleTimeoutSeconds bounds waiting for the next
                // complete WebSocket message start. Half-closed sockets may lag isClosed()
                // until the background parser observes EOF — the timeout is the hard bound.
                $message = $this->connection->receive(
                    new TimeoutCancellation($this->idleTimeoutSeconds),
                );
            } catch (CancelledException $e) {
                $this->logIoTimeout('receive');
                throw new \RuntimeException('Codex WebSocket idle timeout.', previous: $e);
            }

            if (null === $message) {
                throw new \RuntimeException('Codex WebSocket connection closed before response.completed.');
            }

            if (!$message->isText()) {
                throw new \RuntimeException('Codex WebSocket frame was not a text message.');
            }

            // WebsocketMessage::buffer() accepts Cancellation; without it a fragmented
            // message whose continuation never arrives can block indefinitely after receive()
            // returned. Bound buffering with the same idle timeout as receive/send.
            try {
                $payload = $message->buffer(new TimeoutCancellation($this->idleTimeoutSeconds));
            } catch (CancelledException $e) {
                $this->logIoTimeout('buffer');
                throw new \RuntimeException('Codex WebSocket message buffer timeout.', previous: $e);
            }

            if ('' === $payload) {
                continue;
            }

            try {
                $event = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Codex WebSocket frame contained invalid JSON.', previous: $e);
            }

            if (!\is_array($event)) {
                throw new \RuntimeException('Codex WebSocket frame must decode to a JSON object.');
            }

            /* @var array<string, mixed> $event */
            yield $event;

            $type = $event['type'] ?? '';

            // Capture finalized items as they complete. Terminal response.output
            // is preferred when present; these are the fallback when it is not
            // (common for tool-call turns on the WebSocket transport).
            if ('response.output_item.done' === $type && \is_array($event['item'] ?? null)) {
                $this->completedOutputItems[] = $event['item'];
            }

            if ('response.completed' === $type) {
                $this->commitContinuationIfSuccessful($event);
                $streamSucceeded = true;

                return;
            }

            if ('response.done' === $type) {
                if ($this->isSuccessfulTerminalResponse($event)) {
                    $this->commitContinuationIfSuccessful($event);
                    $streamSucceeded = true;

                    return;
                }

                throw new \RuntimeException($this->terminalErrorMessage($type, $event));
            }

            if ('response.failed' === $type || 'response.incomplete' === $type || str_starts_with((string) $type, 'error')) {
                throw new \RuntimeException($this->terminalErrorMessage($type, $event));
            }
        }
    }

    /**
     * Build a terminal error message from a failed stream event.
     *
     * @param array<string, mixed> $event
     */
    private function terminalErrorMessage(string $type, array $event): string
    {
        $error = [];
        $response = $event['response'] ?? null;
        if (\is_array($response) && \is_array($response['error'] ?? null)) {
            $error = $response['error'];
        } elseif (\is_array($event['error'] ?? null)) {
            $error = $event['error'];
        }

        $text = ProviderErrorFormatter::format($error);
        if ('' === $text) {
            return \sprintf('Codex WebSocket stream ended with non-success terminal event (%s).', $type);
        }

        // format() returns "[code/type/param]: message" when structured fields
        // exist and the bare bounded message otherwise.
        if (str_starts_with($text, '[')) {
            return $text;
        }

        return \sprintf('non-success terminal event (%s): %s', $type, $text);
    }

    private function logIoTimeout(string $phase): void
    {
        $lease = $this->cachedStreamContext?->lease;

        $this->logger->warning('codex.websocket.io_timeout', [
            'event_type' => 'codex.websocket.io_timeout',
            'component' => 'raw_websocket_result',
            'phase' => $phase,
            'timeout_seconds' => $this->idleTimeoutSeconds,
            'cache_reused' => null !== $lease && $lease->reused,
            'cache_one_shot' => null !== $lease && $lease->oneShot,
        ]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isSuccessfulTerminalResponse(array $event): bool
    {
        $response = $event['response'] ?? null;
        if (!\is_array($response)) {
            return false;
        }

        $responseId = $response['id'] ?? null;

        return \is_string($responseId) && '' !== $responseId;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function commitContinuationIfSuccessful(array $event): void
    {
        $context = $this->cachedStreamContext;
        if (null === $context || $context->lease->oneShot || null === $context->lease->entry) {
            return;
        }

        $response = $event['response'] ?? null;
        if (!\is_array($response)) {
            return;
        }

        $responseId = $response['id'] ?? null;
        if (!\is_string($responseId) || '' === $responseId) {
            return;
        }

        $context->lease->entry->continuation = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $context->fullRequestBody,
            $responseId,
            $this->resolveContinuationResponseItems($response),
        );
    }

    /**
     * Prefer canonical terminal response.output when populated; otherwise use
     * completed response.output_item.done items accumulated during the stream.
     *
     * When terminal output is present it is authoritative — do not merge with
     * streamed items, which would duplicate function_call/message/reasoning
     * entries already listed there.
     *
     * @param array<string, mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private function resolveContinuationResponseItems(array $response): array
    {
        $output = $response['output'] ?? [];
        if (!\is_array($output)) {
            $output = [];
        }

        $items = [];
        foreach ($output as $item) {
            if (\is_array($item)) {
                $items[] = $item;
            }
        }

        if ([] !== $items) {
            return $items;
        }

        return $this->completedOutputItems;
    }

    private function finalizeCachedLifecycle(bool $success): void
    {
        if ($this->cacheFinalized) {
            return;
        }
        $context = $this->cachedStreamContext;
        if (null === $context) {
            return;
        }
        $this->cacheFinalized = true;

        if ($success && $context->lease->cached && !$context->lease->oneShot) {
            $this->retainConnection = true;
            $context->cache->release($context->lease, true);

            return;
        }

        $context->cache->invalidateEntry($context->lease->entry, $success ? 'stream_end' : 'stream_failure');
        $this->connectionClosed = true;
    }

    /** Idempotent: stream iteration, abort(), and __destruct() all funnel here. */
    private function closeConnection(): void
    {
        if ($this->connectionClosed) {
            return;
        }

        $this->connectionClosed = true;

        if ($this->retainConnection) {
            return;
        }

        try {
            $this->connection->close();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.websocket.close_failed', [
                'event_type' => 'codex.websocket.close_failed',
                'component' => 'raw_websocket_result',
                'exception_class' => $e::class,
            ]);
        }
    }
}
