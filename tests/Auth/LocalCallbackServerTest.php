<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\LocalCallbackServer;

final class LocalCallbackServerTest extends TestCase
{
    #[DataProvider('callbacks')]
    public function testValidatesCallbackBeforeReturningCode(string $target, ?string $expectedCode, int $status): void
    {
        $streamsBefore = get_resources('stream');
        $client = null;
        try {
            $result = (new LocalCallbackServer())->waitForCallback('expected', timeoutSeconds: 1.0, port: 0, afterListen: static function () use ($streamsBefore, $target, &$client): void {
                $listeners = array_diff_key(get_resources('stream'), $streamsBefore);
                self::assertCount(1, $listeners);
                $listener = reset($listeners);
                self::assertIsResource($listener);
                $address = stream_socket_get_name($listener, false);
                self::assertIsString($address);
                $client = stream_socket_client('tcp://'.$address, timeout: 1.0);
                self::assertIsResource($client);
                stream_set_timeout($client, 1);
                fwrite($client, 'GET '.$target." HTTP/1.1\r\nHost: localhost\r\n\r\n");
            });

            $this->assertSame(null === $expectedCode ? null : ['code' => $expectedCode], $result);
            $this->assertIsResource($client);
            $response = stream_get_contents($client);
            $this->assertIsString($response);
            $this->assertStringStartsWith('HTTP/1.1 '.$status, $response);
        } finally {
            if (\is_resource($client)) {
                fclose($client);
            }
        }
    }

    /** @return iterable<string, array{string, ?string, int}> */
    public static function callbacks(): iterable
    {
        yield 'valid' => ['/auth/callback?code=accepted&state=expected', 'accepted', 200];
        yield 'wrong state' => ['/auth/callback?code=rejected&state=wrong', null, 400];
        yield 'missing state' => ['/auth/callback?code=rejected', null, 400];
        yield 'wrong path' => ['/wrong?code=rejected&state=expected', null, 404];
    }

    public function testCallbackFailureClosesListener(): void
    {
        $streamsBefore = get_resources('stream');
        $failure = new \RuntimeException('Browser callback failed');
        $listener = null;
        try {
            (new LocalCallbackServer())->waitForCallback('state', port: 0, afterListen: static function () use ($streamsBefore, $failure, &$listener): void {
                $newStreams = array_diff_key(get_resources('stream'), $streamsBefore);
                self::assertCount(1, $newStreams);
                $listener = reset($newStreams);
                self::assertIsResource($listener);
                throw $failure;
            });
            $this->fail('Callback exception must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertNotNull($listener);
        $this->assertFalse(\is_resource($listener), 'The listener must close even if browser launch fails.');
    }
}
