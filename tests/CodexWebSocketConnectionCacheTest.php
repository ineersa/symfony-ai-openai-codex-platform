<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use Amp\Websocket\Client\WebsocketConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCacheSettings;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketCompatibilityFingerprint;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
final class CodexWebSocketConnectionCacheTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testReusesIdleConnectionForSameSessionIdentity(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings(idleTtlSeconds: 300, maxAgeSeconds: 3300);
        $identity = $this->identity('0194aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee', 'gpt-5.6-luna');

        $connectCount = 0;
        $connection = $this->createMock(WebsocketConnection::class);

        $lease1 = $cache->acquire($identity, $settings, static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });
        $this->assertFalse($lease1->reused);
        $cache->release($lease1, true);

        $lease2 = $cache->acquire($identity, $settings, static function () use (&$connectCount, $connection): WebsocketConnection {
            ++$connectCount;

            return $connection;
        });
        $this->assertTrue($lease2->reused);
        $this->assertSame(1, $connectCount);
    }

    public function testBusyLeaseCreatesOneShotWithoutSecondCacheEntry(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $identity = $this->identity('0194bbbb-bbbb-7ccc-8ddd-eeeeeeeeeeee', 'gpt-5.6-luna');

        $primary = $this->createMock(WebsocketConnection::class);
        $oneShot = $this->createMock(WebsocketConnection::class);
        $oneShot->expects($this->once())->method('close');

        $leaseBusy = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $primary);
        $this->assertFalse($leaseBusy->reused);

        $leaseOneShot = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $oneShot);
        $this->assertTrue($leaseOneShot->oneShot);
        $cache->release($leaseOneShot, false);

        $cache->release($leaseBusy, true);
        $leaseAfter = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $primary);
        $this->assertTrue($leaseAfter->reused);
    }

    public function testBusyWinsOverIdentityMismatchWithoutClosingActiveSocket(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $sessionKey = '0194cccc-bbbb-7ccc-8ddd-eeeeeeeeeeee';

        $primary = $this->createMock(WebsocketConnection::class);
        $primary->expects($this->never())->method('close');
        $oneShot = $this->createMock(WebsocketConnection::class);
        $oneShot->expects($this->once())->method('close');

        $leaseBusy = $cache->acquire($this->identity($sessionKey, 'gpt-5.6-luna'), $settings, static fn (): WebsocketConnection => $primary);

        $leaseOneShot = $cache->acquire($this->identity($sessionKey, 'gpt-5.6-sol'), $settings, static fn (): WebsocketConnection => $oneShot);
        $this->assertTrue($leaseOneShot->oneShot);
        $cache->release($leaseOneShot, false);

        $cache->release($leaseBusy, true);
    }

    public function testIdentityMismatchReplacesCachedEntryAfterRelease(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $sessionKey = '0194dddd-bbbb-7ccc-8ddd-eeeeeeeeeeee';

        $first = $this->createMock(WebsocketConnection::class);
        $first->expects($this->once())->method('close');
        $second = $this->createMock(WebsocketConnection::class);

        $lease1 = $cache->acquire($this->identity($sessionKey, 'gpt-5.6-luna'), $settings, static fn (): WebsocketConnection => $first);
        $cache->release($lease1, true);

        $lease2 = $cache->acquire($this->identity($sessionKey, 'gpt-5.6-sol'), $settings, static fn (): WebsocketConnection => $second);
        $this->assertFalse($lease2->reused);
    }

    public function testMaxAgeExpiryOnAcquireUsesInjectedClock(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-07-13 10:00:00'));
        $cache = new CodexWebSocketConnectionCache(clock: $clock);
        $settings = new CodexWebSocketCacheSettings(maxAgeSeconds: 60);
        $identity = $this->identity('0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee', 'gpt-5.6-luna');

        $first = $this->createMock(WebsocketConnection::class);
        $first->expects($this->once())->method('close');
        $second = $this->createMock(WebsocketConnection::class);

        $lease1 = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $first);
        $cache->release($lease1, true);

        $clock->sleep(61);

        $lease2 = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $second);
        $this->assertFalse($lease2->reused);
    }

    public function testIdleTtlExpiryOnAcquireUsesInjectedClockAndClosesStaleConnection(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-07-13 20:44:00'));
        $cache = new CodexWebSocketConnectionCache(clock: $clock);
        $settings = new CodexWebSocketCacheSettings(idleTtlSeconds: 300, maxAgeSeconds: 3300);
        $identity = $this->identity('0194aaaa-bbbb-7ccc-8ddd-111111111111', 'gpt-5.6-luna');

        $first = $this->createMock(WebsocketConnection::class);
        $first->expects($this->once())->method('close');
        $second = $this->createMock(WebsocketConnection::class);

        $connectCount = 0;
        $lease1 = $cache->acquire($identity, $settings, static function () use (&$connectCount, $first): WebsocketConnection {
            ++$connectCount;

            return $first;
        });
        $cache->release($lease1, true);

        $clock->sleep(308);

        $lease2 = $cache->acquire($identity, $settings, static function () use (&$connectCount, $second): WebsocketConnection {
            ++$connectCount;

            return $second;
        });
        $this->assertFalse($lease2->reused);
        $this->assertSame(2, $connectCount);
    }

    public function testClosedIdleConnectionIsDiscardedBeforeIdleTtl(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings(idleTtlSeconds: 300);
        $identity = $this->identity('0194bbbb-bbbb-7ccc-8ddd-222222222222', 'gpt-5.6-luna');

        $first = $this->createMock(WebsocketConnection::class);
        $first->expects($this->once())->method('close');
        $isClosed = false;
        $first->method('isClosed')->willReturnCallback(static function () use (&$isClosed): bool {
            return $isClosed;
        });

        $lease1 = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $first);
        $cache->release($lease1, true);
        $isClosed = true;

        $second = $this->createMock(WebsocketConnection::class);
        $lease2 = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $second);
        $this->assertFalse($lease2->reused);
    }

    public function testBusyWinsOverIdleTtlWithoutClosingActiveSocket(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-07-13 20:44:00'));
        $cache = new CodexWebSocketConnectionCache(clock: $clock);
        $settings = new CodexWebSocketCacheSettings(idleTtlSeconds: 300);
        $identity = $this->identity('0194cccc-bbbb-7ccc-8ddd-333333333333', 'gpt-5.6-luna');

        $primary = $this->createMock(WebsocketConnection::class);
        $primary->expects($this->never())->method('close');
        $oneShot = $this->createMock(WebsocketConnection::class);
        $oneShot->expects($this->once())->method('close');

        $leaseBusy = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $primary);

        $clock->sleep(400);

        $leaseOneShot = $cache->acquire($identity, $settings, static fn (): WebsocketConnection => $oneShot);
        $this->assertTrue($leaseOneShot->oneShot);
        $cache->release($leaseOneShot, false);

        $cache->release($leaseBusy, true);
    }

    public function testCloseAllClosesOwnedConnections(): void
    {
        $cache = new CodexWebSocketConnectionCache();
        $settings = new CodexWebSocketCacheSettings();
        $connection = $this->createMock(WebsocketConnection::class);
        $connection->expects($this->once())->method('close');

        $lease = $cache->acquire($this->identity('0194ffff-bbbb-7ccc-8ddd-eeeeeeeeeeee', 'gpt-5.6-luna'), $settings, static fn (): WebsocketConnection => $connection);
        $cache->release($lease, true);
        $cache->closeAll();
    }

    private function identity(string $sessionKey, string $model): CodexWebSocketCompatibilityFingerprint
    {
        self::assertUuidVersion7($sessionKey);

        return CodexWebSocketCompatibilityFingerprint::fromContext(
            $sessionKey,
            'openai-codex',
            $model,
            'https://chatgpt.com/backend-api',
            '/codex/responses',
            'acct-1',
        );
    }
}
