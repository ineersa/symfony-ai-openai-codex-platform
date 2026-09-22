# Register the OAuth command

Install the optional dependencies in your application:

```sh
composer require league/oauth2-client:^2.8 symfony/console:^8.1 symfony/process:^8.0
```

Implement `Auth\CodexAuthStorageInterface` in your host credential store. Both methods receive the exact provider key:

```php
public function loadCredentialsRaw(string $providerKey): ?CodexAuthRecord;
public function saveCredentials(string $providerKey, CodexAuthRecord $record): void;
```

Return stored credentials without automatic refresh from `loadCredentialsRaw()`. Preserve the record's `toArray()` format when writing credentials. Protect persisted credentials with your application's permissions, encryption, and locking policy. This package does not select a file path or implement a secret store.

Register the command with Symfony Console:

```php
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthCommand;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\Component\Console\Application;

$config = new CodexOAuthConfig(
	originator: 'my-cli',
	displayName: 'OpenAI Codex',
	commandName: 'auth:codex',
);
$refresher = new CodexTokenRefresher(config: $config);
$service = new CodexOAuthService($credentialStore, $refresher, $config);
$application = new Application();
$application->addCommand(new CodexAuthCommand($service, $config));
$application->run();
```

Supply the same config to the command, service, and refresher so command hints and identity agree. Register these objects through your container when your application already owns a Console application.

Run login or refresh:

```sh
bin/console auth:codex
bin/console auth:codex --no-browser --port=1455 --timeout=300
bin/console auth:codex --auth-profile=work
bin/console auth:codex --refresh --auth-profile=work
```

The callback listener binds before browser launch. If the callback cannot be received, paste the redirect URL or authorization code into the prompt. A supplied state must match the login state. Bare-code input remains supported.

For noninteractive use, obtain tokens through your own login UI and call `CodexTokenRefresher::refresh($refreshToken, $expectedAccountId)` to rotate credentials. Persist the returned record only after success. An account-ID change rejects the refresh.

To customize HTTP transport without replacing the OAuth implementation, inject a Guzzle `ClientInterface` through the `httpClient` argument of the service and refresher. The flow uses `league/oauth2-client` for PKCE, state, and token exchange. It is Codex-specific, not a provider-independent OAuth framework.
