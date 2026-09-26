# Reuse WebSocket connections between turns

Start with the [login and first-request example](../README.md). Cached WebSocket transport can reuse a connection while you keep the cache alive. Give turns in one conversation the same UUIDv7 `prompt_cache_key`.

Use a single cache in your worker and close it at shutdown:

```php
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexTransportEnum;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Bridge\OpenAICodex\Factory;
use Symfony\AI\Platform\Bridge\OpenAICodex\Result\CancellableRawResultInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Uid\Uuid;

$home = getenv('HOME') ?: throw new RuntimeException('HOME is not set.');
$credentials = (new CodexAuthFileStore($home.'/.hatfield/auth.json'))->loadCredentialsRaw();
if (null === $credentials || $credentials->isExpired()) {
	throw new RuntimeException('Run php codex.php auth:codex first.');
}

$cache = new CodexWebSocketConnectionCache();
$provider = Factory::createProvider(
	accessToken: $credentials->access,
	accountId: $credentials->accountId,
	transport: CodexTransportEnum::WebsocketCached,
	websocketConnectionCache: $cache,
);
$sessionKey = (string) Uuid::v7();
try {
	$result = $provider->invoke(
		new CodexModel('gpt-5.6'),
		new MessageBag(Message::ofUser('Hello')),
		['prompt_cache_key' => $sessionKey],
	);
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

Keep `$cache` alive for later turns in the same session; do not close it after each successful response. Aborting a completed result has no effect; abort any result whose stream you stop consuming early. To refresh expired tokens or retry after HTTP 401, use the service and refresher from the README before creating the provider, and pass an `accessTokenRefresher` callback to `Factory::createProvider()`.

To remove application-only invocation options before the request is sent, pass their names through `Factory::createProvider(internalOptions: ['application_run_id'])`. The bridge does not guess host-specific option names.
