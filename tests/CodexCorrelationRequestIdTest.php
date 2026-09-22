<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexCorrelationProvenance;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexCorrelationRequestId;

final class CodexCorrelationRequestIdTest extends TestCase
{
    use AssertUuidV7Trait;

    public function testResolveGeneratesUuidVersion7AndAugmentsPromptCacheKeyWhenNoExplicitIdentifiers(): void
    {
        $resolution = CodexCorrelationRequestId::resolve([], []);

        self::assertUuidVersion7($resolution->id);
        $this->assertSame($resolution->id, $resolution->options['prompt_cache_key']);
        $this->assertSame(CodexCorrelationProvenance::Generated, $resolution->provenance);
        self::assertUuidVersion7($resolution->idFor401Retry());
        $this->assertNotSame($resolution->id, $resolution->idFor401Retry());
    }

    public function testResolveTreatsEmptyPromptCacheKeyAsAbsentAndGeneratesUuidVersion7(): void
    {
        $resolution = CodexCorrelationRequestId::resolve(['prompt_cache_key' => ''], []);

        self::assertUuidVersion7($resolution->id);
        $this->assertSame($resolution->id, $resolution->options['prompt_cache_key']);
        $this->assertSame(CodexCorrelationProvenance::Generated, $resolution->provenance);
    }

    public function testResolveUsesExplicitPromptCacheKeyFromOptions(): void
    {
        $resolution = CodexCorrelationRequestId::resolve(['prompt_cache_key' => 'cache-key-xyz'], []);

        $this->assertSame('cache-key-xyz', $resolution->id);
        $this->assertSame('cache-key-xyz', $resolution->options['prompt_cache_key']);
        $this->assertSame(CodexCorrelationProvenance::ExplicitPromptCacheKey, $resolution->provenance);
        $this->assertSame('cache-key-xyz', $resolution->idFor401Retry());
    }

    public function testResolvePrefersPayloadPromptCacheKeyOverOptions(): void
    {
        $resolution = CodexCorrelationRequestId::resolve(
            ['prompt_cache_key' => 'cache-loses'],
            ['prompt_cache_key' => 'cache-wins'],
        );

        $this->assertSame('cache-wins', $resolution->id);
        $this->assertSame(CodexCorrelationProvenance::ExplicitPromptCacheKey, $resolution->provenance);
        $this->assertSame('cache-wins', $resolution->idFor401Retry());
    }

    public function testResolveIgnoresLegacyInternalCorrelationKeys(): void
    {
        $resolution = CodexCorrelationRequestId::resolve(
            [
                'run_id' => '1',
                'provider_cache_key' => '0194a000-0000-7000-8000-000000000001',
            ],
            [],
        );

        self::assertUuidVersion7($resolution->id);
        $this->assertSame($resolution->id, $resolution->options['prompt_cache_key']);
        $this->assertSame(CodexCorrelationProvenance::Generated, $resolution->provenance);
        $this->assertArrayHasKey('provider_cache_key', $resolution->options);
        $this->assertSame('1', $resolution->options['run_id']);
    }
}
