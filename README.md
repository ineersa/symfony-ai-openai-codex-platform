# OpenAI Codex for Symfony AI

Connect Symfony AI to Codex with your ChatGPT account. Browser login, token refresh, and WebSocket streaming are included.

Requires PHP 8.5+ and a ChatGPT account with Codex access.

## Install the package

```sh
composer require ineersa/symfony-ai-openai-codex-platform
```

## Log in and send a message

Here's a small CLI example, `codex.php`. It saves your login in `var/codex/auth.json` so you don't need to log in for every request. Add that file to `.gitignore`.

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

$refresher = new CodexTokenRefresher();
$store = new CodexAuthFileStore(__DIR__.'/var/codex/auth.json', tokenRefresher: $refresher);
$service = new CodexOAuthService($store, $refresher);

if ('ask' !== ($argv[1] ?? null)) {
	$application = new Application();
	$application->addCommand(new CodexAuthCommand($service));
	exit($application->run());
}

$credentials = $store->loadCredentials();
if (null === $credentials) {
	throw new RuntimeException('Run php codex.php auth:codex first.');
}
$provider = Factory::createProvider(
	accessToken: $credentials->access,
	accountId: $credentials->accountId,
	transport: CodexTransportEnum::Websocket,
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

Log in once in your browser, then run `ask` to stream a reply over WebSocket. The example refreshes expired credentials before sending a request.

For an existing application, see [registering the login command and custom storage](docs/auth.md). For a conversation with multiple turns, see [reusing WebSocket connections](docs/usage.md). Factory options are listed in the [API reference](docs/reference.md).

## Develop

Run `composer install`, then `castor test`, `castor phpstan`, and `castor cs-check`.

[MIT license](LICENSE).
