# Set up Codex login

The optional OAuth command logs in to a ChatGPT account with Codex access. Install its Console, browser, and OAuth dependencies:

```sh
composer require league/oauth2-client:^2.8 symfony/console:^8.1 symfony/process:^8.0
```

For a runnable login and first request, start with the [README](../README.md). To register the command in an existing Symfony Console application, supply a credential path:

```php
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthCommand;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\Component\Console\Application;

$store = new CodexAuthFileStore('/private/path/auth.json');
$config = new CodexOAuthConfig(originator: 'my-cli', commandName: 'auth:codex');
$refresher = new CodexTokenRefresher(config: $config);
$service = new CodexOAuthService($store, $refresher, $config);
$application = new Application();
$application->addCommand(new CodexAuthCommand($service, $config));
$application->run();
```

Supply the same configuration to the command, service, and refresher. The file store writes a private JSON file and updates only the `openai-codex` entry. If another store writes the same file, inject the same Symfony `LockFactory` into both stores so their writes share a lock.

Run `bin/console auth:codex` to log in. Run `bin/console auth:codex --refresh` to exchange the stored refresh token. `--no-browser` prints the authorization URL without opening a browser; `--port` changes the callback port and `--timeout` changes its wait limit. If the listener cannot receive the callback, paste the redirect URL or authorization code. A supplied OAuth state must match the login state.

To use a database or secret manager instead, implement `CodexAuthStorageInterface`:

```php
public function loadCredentialsRaw(): ?CodexAuthRecord;
public function saveCredentials(CodexAuthRecord $record): void;
```

Return the stored record without refreshing it on raw reads. Persist its `toArray()` fields securely. The service validates the account ID on refresh and saves the new record only after a successful exchange. Your storage implementation must serialize refresh attempts if several processes can share an account.

You can inject a Guzzle `ClientInterface` into the OAuth service and token refresher through their `httpClient` arguments. This login is Codex-specific, not a provider-independent OAuth framework.
