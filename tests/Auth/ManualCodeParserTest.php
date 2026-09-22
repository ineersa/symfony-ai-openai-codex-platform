<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\ManualCodeParser;

final class ManualCodeParserTest extends TestCase
{
    public function testParsesFullRedirectUrl(): void
    {
        $result = ManualCodeParser::parse(
            'http://localhost:1455/auth/callback?code=abc123&state=def456',
        );

        $this->assertSame('abc123', $result['code']);
        $this->assertSame('def456', $result['state']);
    }

    public function testParsesUrlWithOnlyCode(): void
    {
        $result = ManualCodeParser::parse(
            'http://localhost:1455/auth/callback?code=abc123',
        );

        $this->assertSame('abc123', $result['code']);
        $this->assertNull($result['state']);
    }

    public function testParsesCodeAndStateAsQueryString(): void
    {
        $result = ManualCodeParser::parse('code=abc123&state=def456');

        $this->assertSame('abc123', $result['code']);
        $this->assertSame('def456', $result['state']);
    }

    public function testParsesBareCodeHashState(): void
    {
        $result = ManualCodeParser::parse('abc123#def456');

        $this->assertSame('abc123', $result['code']);
        $this->assertSame('def456', $result['state']);
    }

    public function testParsesBareCode(): void
    {
        $result = ManualCodeParser::parse('axbYz9876');

        $this->assertSame('axbYz9876', $result['code']);
        $this->assertNull($result['state']);
    }

    public function testReturnsEmptyForEmptyInput(): void
    {
        $result = ManualCodeParser::parse('');

        $this->assertNull($result['code']);
        $this->assertNull($result['state']);
    }

    public function testReturnsEmptyForWhitespaceOnly(): void
    {
        $result = ManualCodeParser::parse("  \n  ");

        $this->assertNull($result['code']);
        $this->assertNull($result['state']);
    }
}
