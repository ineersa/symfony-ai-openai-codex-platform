# Set up Codex login

Start with the [README example](../README.md) for a standalone CLI. In a Symfony Console application, register the login command with a file store:

```php
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthCommand;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\Component\Console\Application;

// Replace 'my-cli' with your application's name.
$config = new CodexOAuthConfig(originator: 'my-cli', commandName: 'auth:codex');
$refresher = new CodexTokenRefresher(config: $config);
$store = new CodexAuthFileStore(__DIR__.'/var/codex/auth.json', tokenRefresher: $refresher);
$service = new CodexOAuthService($store, $refresher, $config);
$application = new Application();
$application->addCommand(new CodexAuthCommand($service, $config));
$application->run();
```

Use the same `$config` for login and refresh. Pass `$config->originator` to `Factory::createProvider()` for API requests too.

If your application uses `bin/console`, run:

```sh
bin/console auth:codex
```

To choose a callback port and open the login URL yourself:

```sh
bin/console auth:codex --port=1456 --timeout=120 --no-browser
```

If the browser callback cannot reach the command, paste the redirect URL or authorization code when prompted. The default port is `1455`, and the default wait is `300` seconds.

To refresh an existing login without opening a browser:

```sh
bin/console auth:codex --refresh
```

## Read saved credentials

Call `$store->loadCredentials()` before creating a provider. With the refresher supplied above, the store refreshes expired tokens while holding the file lock. Use `loadCredentialsRaw()` only when you need the record without a refresh.

The store updates the `openai-codex` entry without deleting other providers' credentials. If other processes write the same file, configure every writer with the same lock backend and location. Pass that shared `LockFactory` to the store's `lockFactory` argument.

## Use another storage backend

To use a database or secret manager instead, implement `CodexAuthStorageInterface`:

```php
public function loadCredentialsRaw(): ?CodexAuthRecord;
public function saveCredentials(CodexAuthRecord $record): void;
```

Return the stored record without refreshing it from `loadCredentialsRaw()`. Save the fields returned by `CodexAuthRecord::toArray()`.

If several processes share the credentials, implement `CodexAuthRefreshStorageInterface` too. Its `refreshWithLock(CodexTokenRefresher $refresher)` method must lock, read the latest record, refresh it, and save the result before releasing the lock. Pass the stored account ID to the refresher so it can reject an unexpected account change. The OAuth service uses this method when the store implements it.

## Customize OAuth HTTP requests

Pass a Guzzle `ClientInterface` through the `httpClient` argument of both `CodexOAuthService` and `CodexTokenRefresher`. This client handles OAuth requests, not model requests.
