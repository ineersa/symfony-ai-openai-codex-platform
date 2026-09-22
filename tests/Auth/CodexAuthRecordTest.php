<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;

/**
 * Unit tests for CodexAuthRecord, particularly the isExpired() method
 * which uses Unix timestamps in seconds.
 */
final class CodexAuthRecordTest extends TestCase
{
    public function testFutureTimestampNotExpired(): void
    {
        $record = new CodexAuthRecord(
            access: 'tok',
            refresh: 'ref',
            expires: time() + 3600, // 1 hour from now (seconds)
            accountId: 'acct',
        );

        $this->assertFalse($record->isExpired());
    }

    public function testPastTimestampIsExpired(): void
    {
        $record = new CodexAuthRecord(
            access: 'tok',
            refresh: 'ref',
            expires: time() - 3600, // 1 hour ago (seconds)
            accountId: 'acct',
        );

        $this->assertTrue($record->isExpired());
    }

    public function testBufferMakesFutureRecordAppearExpired(): void
    {
        // Record expires 30 seconds from now
        $record = new CodexAuthRecord(
            access: 'tok',
            refresh: 'ref',
            expires: time() + 30,
            accountId: 'acct',
        );

        // Buffer of 60 seconds means we declare it expired when <= 60s remain
        $this->assertTrue($record->isExpired(60), 'buffer 60 > 30 remaining = expired');
        $this->assertFalse($record->isExpired(0), 'no buffer = not expired');
    }

    public function testRoundTripSerialization(): void
    {
        $record = new CodexAuthRecord(
            access: 'access-token-123',
            refresh: 'refresh-token-456',
            expires: time() + 3600,
            accountId: 'chat-abc789',
            type: 'oauth',
        );

        $data = $record->toArray();
        $restored = CodexAuthRecord::fromArray($data);

        $this->assertSame($record->access, $restored->access);
        $this->assertSame($record->refresh, $restored->refresh);
        $this->assertSame($record->expires, $restored->expires);
        $this->assertSame($record->accountId, $restored->accountId);
        $this->assertSame('oauth', $restored->type);
    }

    public function testFromArrayThrowsOnMissingFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required fields');

        CodexAuthRecord::fromArray(['type' => 'oauth', 'expires' => 1234567890]);
    }
}
