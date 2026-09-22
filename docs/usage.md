# Connect an application

Install the bridge:

```sh
composer require ineersa/symfony-ai-openai-codex-platform
```

Supply an access token and its matching ChatGPT account ID from your credential store:

```php
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$provider = Factory::createProvider(
	accessToken: $credentials->access,
	accountId: $credentials->accountId,
	originator: 'my-cli',
	userAgent: 'my-cli/1.0',
	transport: CodexTransportEnum::Sse,
);

$result = $provider->invoke(
	new CodexModel('gpt-5.5'),
	new MessageBag(Message::ofUser('Hello')),
);
foreach ($result->asTextStream() as $text) {
	echo $text;
}
```

Choose a model available to your account. Pass a custom `modelCatalog` when your application manages model discovery.

For forced refresh after HTTP 401, pass `accessTokenRefresher` as a closure returning a fresh access-token string or `null`. Keep account-consistency validation and persistence in that callback. The bridge attempts at most one refresh and one retry.

## Retain WebSocket connections

Create one `CodexWebSocketConnectionCache` in your application's dependency container. Pass it to each provider that uses `WebsocketCached`:

```php
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\Result\CancellableRawResultInterface;

$cache = new CodexWebSocketConnectionCache();
$provider = Factory::createProvider(
	accessToken: $credentials->access,
	accountId: $credentials->accountId,
	transport: CodexTransportEnum::WebsocketCached,
	websocketConnectionCache: $cache,
);

try {
	$result = $provider->invoke($model, $messages, ['prompt_cache_key' => $sessionUuidV7]);
	try {
		foreach ($result->asTextStream() as $text) {
			echo $text;
		}
	} finally {
		$raw = $result->getRawResult();
		if ($raw instanceof CancellableRawResultInterface) {
			$raw->abort();
		}
	}
} finally {
	$cache->closeAll();
}
```

In a long-running worker, call `closeAll()` at worker shutdown rather than after each successful turn. Call `abort()` whenever you abandon an unfinished stream. Supply a stable UUIDv7 `prompt_cache_key` for turns in the same session.

To remove application-only invocation options before transmission, pass their names through `Factory::createProvider(internalOptions: ['application_run_id'])`. The bridge does not infer host-specific option names.
