# API and ownership reference

All package classes use the `Symfony\AI\Platform\Bridge\OpenAICodex` namespace.

## Provider factory

`Factory::createProvider()` returns Symfony AI's `ProviderInterface`. Its transport default is `CodexTransportEnum::Websocket`.

| Argument | Default or contract |
| --- | --- |
| `baseUrl` | `https://chatgpt.com/backend-api` |
| `responsesPath` | `/codex/responses` |
| `accessToken`, `accountId` | Credentials supplied by the caller |
| `originator`, `userAgent` | `symfony-ai-openai-codex` |
| `name` | `openai-codex` |
| `httpClient` | Symfony HttpClient for SSE |
| `modelCatalog` | `CodexModelCatalog` |
| `contract` | `Contract\CodexContract::create()` |
| `eventDispatcher`, `logger` | Optional Symfony event dispatcher and PSR logger |
| `accessTokenRefresher` | Optional closure returning a fresh access-token string or `null` |
| `transport` | `Websocket`, `WebsocketCached`, or `Sse` |
| `websocketConnector` | `AmpCodexWebSocketConnector` |
| `websocketConnectionCache` | Caller-owned cache for cached transport |
| `websocketCacheSettings` | Idle TTL 60 seconds, maximum age 3300 seconds |
| `internalOptions` | Empty list. Additional host-only request keys to consume |

`CodexRequestBodyFactory` always consumes `codex_reasoning_update` and `codex_reasoning_reset`. Configured internal keys are removed after payload and option merging, so a payload cannot reintroduce them. Unlisted keys retain their existing request semantics.

## Transport lifecycle

SSE and WebSocket requests share body normalization, UUIDv7 correlation, result conversion, and bounded 401 refresh. WebSocket results are streaming-only. A valid explicit UUIDv7 correlation key permits cache reuse. Generated keys are transient.

The host owns `CodexWebSocketConnectionCache` and closes it at worker shutdown through `closeAll()`. The cache is instance-scoped, not static. It validates provider, model, endpoint, account, age, and continuation compatibility. A busy session uses an isolated one-shot connection.

`Result\CancellableRawResultInterface::abort(): void` is the host cancellation contract. `RawWebSocketResult` implements it. Abandoning or failing a stream invalidates its cached entry. A completed stream can retain its connection for compatible continuation.

Structured transport logs omit raw prompts, tool content, and bearer tokens. `Error\ProviderErrorFormatter` bounds provider diagnostics. Provider error messages can still contain provider-supplied text.

## OAuth ownership

`Auth\CodexOAuthConfig` configures `originator`, `displayName`, and `commandName`. OpenAI client ID, endpoints, scopes, and redirect defaults remain Codex-specific. Credentials use the `openai-codex` key.

`Auth\CodexAuthCommand` extends Symfony Console `Command`. It accepts `--refresh`, `--port`, `--timeout`, and `--no-browser`. It uses the input supplied by Console, including programmatic command execution.

`Auth\CodexAuthRecord` preserves the wire fields `type`, `access`, `refresh`, `expires`, and `accountId`. `expires` is a Unix timestamp in seconds. `isExpired()` applies a 60-second buffer by default.

`Auth\CodexAuthFileStore` persists credentials at a caller-supplied path without replacing entries for other providers. Applications may implement `Auth\CodexAuthStorageInterface` for another storage backend. The host chooses whether to refresh expired credentials automatically on read. The package provides the login flow, callback handling, browser helper, manual-code parser, and account-consistent refresh.

OAuth dependencies are optional Composer suggestions. Transport operation does not load `Auth` classes or require Console, browser launching, or a credential store.
