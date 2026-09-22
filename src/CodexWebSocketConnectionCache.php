<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\Websocket\Client\WebsocketConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Process-scoped cache of Codex WebSocket connections keyed by session correlation identity.
 *
 * Idle TTL is enforced synchronously on acquire (not via a background event loop), because
 * long-lived Messenger workers block between messages and Revolt timers do not run reliably.
 */
final class CodexWebSocketConnectionCache
{
    /** @var array<string, CodexWebSocketCacheEntry> */
    private array $entries = [];

    private readonly LoggerInterface $logger;

    private readonly ClockInterface $clock;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? new NativeClock();
    }

    public function acquire(
        CodexWebSocketCompatibilityFingerprint $identity,
        CodexWebSocketCacheSettings $settings,
        callable $connect,
    ): CodexWebSocketCacheLease {
        $sessionKey = $identity->sessionKey;
        $existing = $this->entries[$sessionKey] ?? null;

        if (null !== $existing) {
            // Busy wins: never close, expire, or replace an actively streaming cached socket.
            if ($existing->busy) {
                $connection = $connect();
                $this->logger->info('codex.websocket.cache.busy_one_shot', [
                    'event_type' => 'codex.websocket.cache.busy_one_shot',
                    'component' => 'codex_websocket_connection_cache',
                    'session_key_length' => \strlen($sessionKey),
                ]);

                return new CodexWebSocketCacheLease($connection, false, false, true, null);
            }

            $discardReason = $this->discardReasonForIdleEntry($existing, $identity, $settings);
            if (null !== $discardReason) {
                $this->closeEntry($existing, $discardReason);
                unset($this->entries[$sessionKey]);
                if ('idle_ttl' === $discardReason) {
                    $this->logger->info('codex.websocket.cache.expired', [
                        'event_type' => 'codex.websocket.cache.expired',
                        'component' => 'codex_websocket_connection_cache',
                        'reason' => 'idle_ttl',
                        'session_key_length' => \strlen($sessionKey),
                    ]);
                }
                $existing = null;
            } else {
                $existing->busy = true;
                $existing->idleSince = null;
                $this->logger->info('codex.websocket.cache.reused', [
                    'event_type' => 'codex.websocket.cache.reused',
                    'component' => 'codex_websocket_connection_cache',
                    'session_key_length' => \strlen($sessionKey),
                ]);

                return new CodexWebSocketCacheLease($existing->connection, true, true, false, $existing);
            }
        }

        $connection = $connect();
        $entry = new CodexWebSocketCacheEntry(
            $connection,
            $identity,
            $this->clock->now()->getTimestamp(),
        );
        $this->entries[$sessionKey] = $entry;

        $this->logger->info('codex.websocket.cache.created', [
            'event_type' => 'codex.websocket.cache.created',
            'component' => 'codex_websocket_connection_cache',
            'session_key_length' => \strlen($sessionKey),
        ]);

        return new CodexWebSocketCacheLease($connection, false, true, false, $entry);
    }

    public function release(CodexWebSocketCacheLease $lease, bool $keepInCache): void
    {
        if ($lease->oneShot || !$lease->cached || null === $lease->entry) {
            $this->closeConnection($lease->connection, 'one_shot');

            return;
        }

        $sessionKey = $lease->entry->identity->sessionKey;
        if (($this->entries[$sessionKey] ?? null) !== $lease->entry) {
            $this->closeConnection($lease->connection, 'stale_entry');

            return;
        }

        if (!$keepInCache) {
            $this->closeEntry($lease->entry, 'release_discard');
            unset($this->entries[$sessionKey]);

            return;
        }

        $lease->entry->busy = false;
        $lease->entry->idleSince = $this->clock->now()->getTimestamp();
    }

    public function invalidateEntry(?CodexWebSocketCacheEntry $entry, string $reason): void
    {
        if (null === $entry) {
            return;
        }

        $sessionKey = $entry->identity->sessionKey;
        if (($this->entries[$sessionKey] ?? null) !== $entry) {
            $this->closeConnection($entry->connection, $reason);

            return;
        }

        $entry->continuation = null;
        $entry->idleSince = null;
        $this->closeEntry($entry, $reason);
        unset($this->entries[$sessionKey]);

        $this->logger->info('codex.websocket.cache.reset', [
            'event_type' => 'codex.websocket.cache.reset',
            'component' => 'codex_websocket_connection_cache',
            'reason' => $reason,
            'session_key_length' => \strlen($sessionKey),
        ]);
    }

    public function closeSession(string $sessionKey): void
    {
        $entry = $this->entries[$sessionKey] ?? null;
        if (null === $entry) {
            return;
        }

        $this->invalidateEntry($entry, 'session_close');
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->entries) as $sessionKey) {
            $this->closeSession($sessionKey);
        }
    }

    private function discardReasonForIdleEntry(
        CodexWebSocketCacheEntry $entry,
        CodexWebSocketCompatibilityFingerprint $identity,
        CodexWebSocketCacheSettings $settings,
    ): ?string {
        if ($entry->connection->isClosed()) {
            return 'connection_closed';
        }

        if ($entry->identity->fingerprint !== $identity->fingerprint) {
            return 'identity_mismatch';
        }

        if ($this->isMaxAgeExpired($entry, $settings)) {
            return 'max_age';
        }

        if ($this->isIdleTtlExpired($entry, $settings)) {
            return 'idle_ttl';
        }

        return null;
    }

    private function isMaxAgeExpired(CodexWebSocketCacheEntry $entry, CodexWebSocketCacheSettings $settings): bool
    {
        $now = $this->clock->now()->getTimestamp();

        return ($now - $entry->createdAt) >= $settings->maxAgeSeconds;
    }

    private function isIdleTtlExpired(CodexWebSocketCacheEntry $entry, CodexWebSocketCacheSettings $settings): bool
    {
        if (null === $entry->idleSince) {
            return false;
        }

        $now = $this->clock->now()->getTimestamp();

        return ($now - $entry->idleSince) >= $settings->idleTtlSeconds;
    }

    private function closeEntry(CodexWebSocketCacheEntry $entry, string $reason): void
    {
        $entry->continuation = null;
        $entry->idleSince = null;
        $this->closeConnection($entry->connection, $reason);
    }

    private function closeConnection(WebsocketConnection $connection, string $reason): void
    {
        try {
            $connection->close();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.websocket.close_failed', [
                'event_type' => 'codex.websocket.close_failed',
                'component' => 'codex_websocket_connection_cache',
                'reason' => $reason,
                'exception_class' => $e::class,
            ]);
        }
    }
}
