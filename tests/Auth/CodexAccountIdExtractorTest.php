<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAccountIdExtractor;

final class CodexAccountIdExtractorTest extends TestCase
{
    public function testExtractsAccountIdFromValidJwt(): void
    {
        $jwt = self::createJwt([
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'chat-abc123def456',
            ],
        ]);

        $result = CodexAccountIdExtractor::extract($jwt);

        $this->assertSame('chat-abc123def456', $result);
    }

    public function testReturnsNullWhenClaimPathMissing(): void
    {
        $jwt = self::createJwt([
            'sub' => 'user_123',
        ]);

        $result = CodexAccountIdExtractor::extract($jwt);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenAccountIdMissing(): void
    {
        $jwt = self::createJwt([
            'https://api.openai.com/auth' => [
                'sub' => 'user_123',
            ],
        ]);

        $result = CodexAccountIdExtractor::extract($jwt);

        $this->assertNull($result);
    }

    public function testReturnsNullOnMalformedToken(): void
    {
        $result = CodexAccountIdExtractor::extract('not-a-jwt');
        $this->assertNull($result);
    }

    public function testReturnsNullOnEmptyToken(): void
    {
        $result = CodexAccountIdExtractor::extract('');
        $this->assertNull($result);
    }

    public function testReturnsNullOnInvalidBase64Payload(): void
    {
        $jwt = 'header.!!!invalid-base64!!.signature';
        $result = CodexAccountIdExtractor::extract($jwt);
        $this->assertNull($result);
    }

    public function testReturnsNullOnNonJsonPayload(): void
    {
        $payloadB64 = self::base64urlEncode('not-json-at-all');
        $jwt = 'header.'.$payloadB64.'.sig';

        $result = CodexAccountIdExtractor::extract($jwt);

        $this->assertNull($result);
    }

    /**
     * Build a valid JWT with the given payload.
     *
     * @param array<string, mixed> $payload
     */
    private static function createJwt(array $payload): string
    {
        $header = self::base64urlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body = self::base64urlEncode(json_encode($payload));
        $sig = self::base64urlEncode('fake-signature');

        return $header.'.'.$body.'.'.$sig;
    }

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
