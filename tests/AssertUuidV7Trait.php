<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\Assert;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

trait AssertUuidV7Trait
{
    protected static function assertUuidVersion7(string $id, string $message = ''): void
    {
        Assert::assertInstanceOf(UuidV7::class, Uuid::fromString($id), $message);
    }
}
