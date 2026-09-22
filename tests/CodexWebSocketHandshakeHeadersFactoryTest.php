<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketHandshakeHeadersFactory;

final class CodexWebSocketHandshakeHeadersFactoryTest extends TestCase
{
    public function testCreatesRequiredHeadersWithoutSseFields(): void
    {
        $factory = new CodexWebSocketHandshakeHeadersFactory();
        $headers = $factory->create('token', 'acct', 'symfony-ai-openai-codex', 'req-uuid');

        $this->assertSame('Bearer token', $headers['Authorization']);
        $this->assertSame('acct', $headers['chatgpt-account-id']);
        $this->assertSame('symfony-ai-openai-codex', $headers['originator']);
        $this->assertSame('symfony-ai-openai-codex', $headers['User-Agent']);
        $this->assertSame('req-uuid', $headers['session-id']);
        $this->assertSame('req-uuid', $headers['x-client-request-id']);
        $this->assertSame('responses_websockets=2026-02-06', $headers['OpenAI-Beta']);
        $this->assertArrayNotHasKey('Accept', $headers);
        $this->assertArrayNotHasKey('Content-Type', $headers);
    }
}
