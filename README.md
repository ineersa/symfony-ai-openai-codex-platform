# OpenAI Codex for Symfony AI

Connect Symfony AI to Codex with your ChatGPT account. Browser login, token refresh, and WebSocket streaming are included.

The `websocket-cached` transport reuses WebSocket connections between turns. GPT-5.6 and newer Codex models require WebSocket and no longer support SSE.

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
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Application;

// Use your application's name instead of 'my-cli'. Share this identity
// between OAuth login, token refresh, and API requests.
$config = new CodexOAuthConfig(
	originator: 'my-cli',
	displayName: 'OpenAI Codex',
	commandName: 'auth:codex',
);
$refresher = new CodexTokenRefresher(config: $config);

// Choose where your application stores credentials. The store refreshes
// expired tokens under a file lock when loadCredentials() is called.
$store = new CodexAuthFileStore(__DIR__.'/var/codex/auth.json', tokenRefresher: $refresher);
$service = new CodexOAuthService($store, $refresher, $config);

// Register the login command. An existing Console app can register it too.
if ('ask' !== ($argv[1] ?? null)) {
	$application = new Application();
	$application->addCommand(new CodexAuthCommand($service, $config));
	exit($application->run());
}

$credentials = $store->loadCredentials();
if (null === $credentials) {
	throw new RuntimeException('Run php codex.php auth:codex first.');
}
$provider = Factory::createProvider(
	accessToken: $credentials->access,
	accountId: $credentials->accountId,
	originator: $config->originator,
	userAgent: 'my-cli/1.0', // Replace with your application's name and version.
	transport: CodexTransportEnum::Websocket,
	// Retry once with a fresh token if the provider rejects the current one.
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

### Login options

| Option | Default | What it does |
| --- | --- | --- |
| `--port` | `1455` | Port for the local OAuth callback listener |
| `--timeout` | `300` | Seconds to wait for the browser callback |
| `--no-browser` | Off | Print the login URL without opening a browser |
| `--refresh` | Off | Refresh saved credentials instead of starting a new login |

For example, choose another callback port and open the login URL yourself:

```sh
php codex.php auth:codex --port=1456 --timeout=120 --no-browser
```

To refresh an existing login:

```sh
php codex.php auth:codex --refresh
```

Use `php codex.php auth:codex --help` for help, including standard Symfony Console options.

For an existing application, see [registering the login command and custom storage](docs/auth.md). For a conversation with multiple turns, see [reusing WebSocket connections](docs/usage.md). Factory options are listed in the [API reference](docs/reference.md).

## Develop

Run `composer install`, then `castor test`, `castor phpstan`, and `castor cs-check`.

[MIT license](LICENSE).
