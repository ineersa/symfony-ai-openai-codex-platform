# Reuse WebSocket connections between turns

Start with the [login and first-request example](../README.md). To reuse a WebSocket connection, keep one cache alive across requests and use the same UUIDv7 `prompt_cache_key` for each conversation.

This example shows one request and its cleanup. In a worker, put the conversation loop inside the outer `try` block and close the cache only at shutdown:

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

$credentials = (new CodexAuthFileStore(__DIR__.'/var/codex/auth.json'))->loadCredentialsRaw();
if (null === $credentials || $credentials->isExpired()) {
	throw new RuntimeException('Run php codex.php auth:codex first.');
}

$cache = new CodexWebSocketConnectionCache();
$provider = Factory::createProvider(
	accessToken: $credentials->access,
	accountId: $credentials->accountId,
	originator: 'my-cli', // Use your application's name.
	userAgent: 'my-cli/1.0',
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

For later turns, pass the conversation history, including prior assistant responses and tool results. Reusing a connection does not replace request history.

Call `abort()` if you stop consuming a stream early. After a completed stream, it has no effect. For automatic token refresh, use the file store and refresher setup from the README and pass its `accessTokenRefresher` callback to the factory.

Connection reuse does not guarantee a prompt-cache hit. The provider can report zero cached tokens even on a successful continuation.

To remove application-only invocation options before the request is sent, pass their names through `Factory::createProvider(internalOptions: ['application_run_id'])`. The bridge does not guess host-specific option names.
