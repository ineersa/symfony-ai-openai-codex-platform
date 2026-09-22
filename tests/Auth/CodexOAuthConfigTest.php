<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthConfig;

final class CodexOAuthConfigTest extends TestCase
{
    #[DataProvider('validProfileProvider')]
    public function testProviderKeyForProfileWithValidNames(?string $profile, string $expectedKey): void
    {
        $key = CodexOAuthConfig::providerKeyForProfile($profile);
        $this->assertSame($expectedKey, $key);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string}>
     */
    public static function validProfileProvider(): iterable
    {
        yield 'null defaults to openai-codex' => [null, 'openai-codex'];
        yield 'empty string defaults to openai-codex' => ['', 'openai-codex'];
        yield 'spaces only defaults to openai-codex' => ['   ', 'openai-codex'];
        yield 'simple profile name' => ['work', 'openai-codex-work'];
        yield 'hyphenated profile' => ['my-account', 'openai-codex-my-account'];
        yield 'underscore profile' => ['personal_2', 'openai-codex-personal_2'];
        yield 'mixed alphanumeric' => ['acct2', 'openai-codex-acct2'];
        yield 'normalized to lowercase' => ['Work', 'openai-codex-work'];
        yield 'mixed case normalized' => ['MyProfile', 'openai-codex-myprofile'];
        yield 'digits only' => ['2', 'openai-codex-2'];
    }

    #[DataProvider('invalidProfileProvider')]
    public function testProviderKeyForProfileRejectsInvalidNames(string $profile): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid profile name');

        CodexOAuthConfig::providerKeyForProfile($profile);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidProfileProvider(): iterable
    {
        yield 'contains slash' => ['work/personal'];
        yield 'contains backslash' => ['work\\personal'];
        yield 'contains dot' => ['personal.'];
        yield 'double dot' => ['..'];
        yield 'contains space' => ['my profile'];
        yield 'leading dot' => ['.hidden'];
        yield 'contains special chars' => ['work@home'];
        yield 'contains dollar' => ['acct$'];
    }

    // -- profileFromProviderKey tests --

    #[DataProvider('profileFromProviderKeyProvider')]
    public function testProfileFromProviderKey(string $providerKey, ?string $expectedProfile): void
    {
        $this->assertSame($expectedProfile, CodexOAuthConfig::profileFromProviderKey($providerKey));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function profileFromProviderKeyProvider(): iterable
    {
        yield 'default key returns null' => ['openai-codex', null];
        yield 'profile key returns profile' => ['openai-codex-work', 'work'];
        yield 'hyphenated profile' => ['openai-codex-my-account', 'my-account'];
        yield 'underscore profile' => ['openai-codex-personal_2', 'personal_2'];
        yield 'digits profile' => ['openai-codex-2', '2'];
        yield 'malformed no suffix' => ['openai-codex-', null];
        yield 'custom non-profile key' => ['my-custom-key', null];
        yield 'empty string' => ['', null];
        yield 'totally unrelated key' => ['other-provider', null];
    }

    // -- authCommandHintForProviderKey tests --

    #[DataProvider('authCommandHintProvider')]
    public function testAuthCommandHintForProviderKey(string $providerKey, string $expectedHint): void
    {
        $hint = CodexOAuthConfig::authCommandHintForProviderKey($providerKey);
        $this->assertSame($expectedHint, $hint);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function authCommandHintProvider(): iterable
    {
        yield 'default key shows base command' => [
            'openai-codex',
            'bin/console auth:codex',
        ];
        yield 'profile key appends profile flag' => [
            'openai-codex-work',
            'bin/console auth:codex --auth-profile=work',
        ];
        yield 'custom key does not append profile flag' => [
            'my-custom-key',
            'bin/console auth:codex',
        ];
        yield 'malformed suffix does not append profile flag' => [
            'openai-codex-',
            'bin/console auth:codex',
        ];
        yield 'empty key shows base command' => [
            '',
            'bin/console auth:codex',
        ];
    }
}
