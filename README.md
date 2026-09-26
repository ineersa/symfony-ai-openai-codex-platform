# OpenAI Codex for Symfony AI

Use a ChatGPT subscription to send Codex Responses requests through Symfony AI. This library talks to the Codex subscription endpoint, not the OpenAI API-key endpoint. You need a ChatGPT account with Codex access. It is an independent package, not an official Symfony or OpenAI bridge.

The package needs PHP 8.5 or later and Symfony AI 0.12 or 0.13. It can send requests over SSE, WebSocket, or a cached WebSocket connection. Login uses OAuth PKCE; no OpenAI API key is needed.

## Install the package

Until the package is published on Packagist, tell Composer where to find it:

```sh
composer config repositories.openai-codex vcs https://github.com/ineersa/symfony-ai-openai-codex-platform
composer require ineersa/symfony-ai-openai-codex-platform:dev-main
composer require league/oauth2-client:^2.8 symfony/console:^8.1 symfony/process:^8.0
```

Commit your `composer.lock` to keep the package revision fixed. The last three dependencies enable browser login and the optional Console command.

## Log in and send a message

Save this as `codex.php` in the directory that contains `vendor/`. The file store keeps credentials in `~/.hatfield/auth.json`; replace that path if you prefer another location. It creates private directories and writes the file with mode `0600`. Do not commit the credentials file.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthCommand;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Application;

$home = getenv('HOME') ?: throw new RuntimeException('HOME is not set.');
$store = new CodexAuthFileStore($home.'/.hatfield/auth.json');
$service = new CodexOAuthService($store, new CodexTokenRefresher());

if ('ask' !== ($argv[1] ?? null)) {
	$application = new Application();
	$application->addCommand(new CodexAuthCommand($service));
	exit($application->run());
}

$credentials = $store->loadCredentialsRaw();
if (null === $credentials) {
	throw new RuntimeException('Run php codex.php auth:codex first.');
}
if ($credentials->isExpired()) {
	$credentials = $service->refreshCredentials();
}

$provider = Factory::createProvider(
	accessToken: $credentials->access,
	accountId: $credentials->accountId,
	transport: CodexTransportEnum::Sse,
	accessTokenRefresher: static fn (): string => $service->refreshCredentials()->access,
);
$result = $provider->invoke(
	new CodexModel('gpt-5.6'),
	new MessageBag(Message::ofUser('Hello')),
);
foreach ($result->asTextStream() as $text) {
	echo $text;
}
echo PHP_EOL;
```

Run the two commands:

```sh
php codex.php auth:codex
php codex.php ask
```

The first command opens a browser for login. If the callback cannot reach your machine, paste the redirect URL or authorization code into the command prompt. The second command prints a streamed response. Change `gpt-5.6` to a Codex model available to your account if needed.

`CodexAuthFileStore` retains other providers' entries when it updates `auth.json`. If another process also writes that file, pass both stores the same Symfony `LockFactory` so they share a file-scoped lock. For a database or secret manager, implement `CodexAuthStorageInterface` instead. The package does not refresh credentials during raw reads; the example refreshes before sending and once more on an authorization failure.

## Choose a transport

The example uses SSE, which needs no connection cache. The default transport is WebSocket. To reuse WebSocket connections between turns, choose `CodexTransportEnum::WebsocketCached`, keep one `CodexWebSocketConnectionCache` in your worker, and close it on shutdown. Abort an unfinished result before discarding it. [Connection management](docs/usage.md) has a complete cached-transport example.

See [OAuth configuration](docs/auth.md) for command options and custom storage, and [the API reference](docs/reference.md) for factory arguments and lifecycle details. The package is distributed under the [MIT license](LICENSE).

## Develop

Run `composer install`, then `castor test`, `castor phpstan`, and `castor cs-check`. Package tests use mock provider responses and local sockets. They never read your saved credentials or contact OpenAI.
