<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthCommand;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support\CallbackServer;
use Symfony\AI\Platform\Bridge\OpenAICodex\Tests\Support\InMemoryAuthStorage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CodexAuthCommandTest extends TestCase
{
    public function testLoginPersistsProfileAndUsesConfiguredIdentityAndPkce(): void
    {
        $requests = [];
        $handler = HandlerStack::create(new MockHandler([$this->tokenResponse('account')]));
        $handler->push(Middleware::history($requests));
        $storage = new InMemoryAuthStorage();
        $callback = new CallbackServer();
        $config = new CodexOAuthConfig(originator: 'my-cli', displayName: 'My Codex', commandName: 'login:codex');
        $service = new CodexOAuthService($storage, config: $config, callbackServer: $callback, httpClient: new Client(['handler' => $handler]));
        $command = new CodexAuthCommand($service, $config);
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find('login:codex'));

        $tester->execute(['--no-browser' => true, '--auth-profile' => 'Work', '--port' => '1555', '--timeout' => '7']);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(1555, $callback->port);
        $this->assertSame(7.0, $callback->timeout);
        $this->assertSame(['openai-codex-work'], array_keys($storage->records));
        $this->assertSame('account', $storage->records['openai-codex-work']->accountId);
        $this->assertStringContainsString('My Codex authentication successful', $tester->getDisplay());
        $this->assertStringNotContainsString('refresh-secret', $tester->getDisplay());
        $this->assertCount(1, $requests);
        parse_str((string) $requests[0]['request']->getBody(), $form);
        $this->assertSame('authorization-code', $form['code']);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('http://localhost:1555/auth/callback', $form['redirect_uri']);
        $this->assertArrayNotHasKey('client_secret', $form);
        $this->assertIsString($form['code_verifier']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $form['code_verifier'], true)), '+/', '-_'), '=');
        $this->assertStringContainsString('code_challenge='.$challenge, $tester->getDisplay());
        $this->assertStringContainsString('originator=my-cli', $tester->getDisplay());
        $this->assertNotNull($callback->state);
        $this->assertNotSame('', $callback->state);
        $this->assertStringContainsString('state='.$callback->state, $tester->getDisplay());
    }

    public function testRefreshReplacesOnlySelectedProfile(): void
    {
        $storage = new InMemoryAuthStorage();
        $old = new CodexAuthRecord('old', 'old-refresh', 1, 'account');
        $storage->records = ['openai-codex' => $old, 'openai-codex-work' => $old];
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$this->tokenResponse('account')]))]);
        $service = new CodexOAuthService($storage, new CodexTokenRefresher(httpClient: $client));
        $tester = new CommandTester(new CodexAuthCommand($service));

        $tester->execute(['--refresh' => true, '--auth-profile' => 'work']);

        $tester->assertCommandIsSuccessful();
        $this->assertSame($old, $storage->records['openai-codex']);
        $this->assertSame('refresh-secret', $storage->records['openai-codex-work']->refresh);
        $this->assertStringNotContainsString('refresh-secret', $tester->getDisplay());
    }

    public function testAccountChangeOnRefreshCannotOverwriteCredentials(): void
    {
        $storage = new InMemoryAuthStorage();
        $old = new CodexAuthRecord('old', 'old-refresh', 1, 'account');
        $storage->records['openai-codex-work'] = $old;
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$this->tokenResponse('other-account')]))]);
        $config = new CodexOAuthConfig(commandName: 'login:codex');
        $service = new CodexOAuthService($storage, new CodexTokenRefresher(httpClient: $client), $config);
        $tester = new CommandTester(new CodexAuthCommand($service, $config));

        $this->assertSame(1, $tester->execute(['--refresh' => true, '--auth-profile' => 'work']));
        $this->assertSame($old, $storage->records['openai-codex-work']);
        $this->assertStringContainsString('login:codex --auth-profile=work', $tester->getDisplay());
        $this->assertStringNotContainsString('other-account', $tester->getDisplay());
    }

    public function testManualRedirectStateMismatchDoesNotExchangeCode(): void
    {
        $storage = new InMemoryAuthStorage();
        $handler = new MockHandler([]);
        $service = new CodexOAuthService($storage, callbackServer: new CallbackServer(null), httpClient: new Client(['handler' => HandlerStack::create($handler)]));
        $tester = new CommandTester(new CodexAuthCommand($service));
        $tester->setInputs(['http://localhost:1455/auth/callback?code=code&state=wrong']);

        $this->assertSame(1, $tester->execute(['--no-browser' => true], ['interactive' => true]));
        $this->assertStringContainsString('State mismatch', $tester->getDisplay());
        $this->assertNull($handler->getLastRequest());
        $this->assertSame([], $storage->records);
    }

    public function testInvalidProfileIsRejectedBeforeLogin(): void
    {
        $storage = new InMemoryAuthStorage();
        $callback = new CallbackServer();
        $tester = new CommandTester(new CodexAuthCommand(new CodexOAuthService($storage, callbackServer: $callback)));

        $this->assertSame(1, $tester->execute(['--auth-profile' => '../other', '--no-browser' => true]));
        $this->assertNull($callback->state);
        $this->assertSame([], $storage->records);
    }

    private function tokenResponse(string $account): Response
    {
        $payload = base64_encode(json_encode([CodexOAuthConfig::JWT_CLAIM_PATH => ['chatgpt_account_id' => $account]], \JSON_THROW_ON_ERROR));

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'header.'.$payload.'.signature',
            'refresh_token' => 'refresh-secret',
            'expires_in' => 3600,
        ], \JSON_THROW_ON_ERROR));
    }
}
