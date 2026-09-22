<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Collecting PSR-3 logger for tests.
 *
 * Records every log call with level, message, and context in a public array.
 * Replace ad-hoc WorkerTraceLogger copies with this shared implementation.
 *
 * Usage:
 *   $logger = new TestLogger();
 *   $sut->setLogger($logger);
 *   self::assertCount(2, $logger->records);
 *   self::assertStringContainsString('expected', $logger->records[0]['message']);
 */
final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
