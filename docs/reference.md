# API reference

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

`CodexRequestBodyFactory` removes `codex_reasoning_update`, `codex_reasoning_reset`, `codex_continuation_reset`, and `codex_continuation_generation` before sending a request. It also removes keys listed in `internalOptions`, after merging the payload and options.

## Transport lifecycle

GPT-5.6 and newer Codex models require WebSocket. `Websocket` opens a connection for each request. `WebsocketCached` can reuse a connection across turns. Both return streaming results. SSE remains available for models that support it.

Cached transport requires a caller-supplied UUIDv7 session key. A generated request key does not enable reuse. The bridge attempts at most one token refresh and retry after an authorization failure.

Each `CodexWebSocketConnectionCache` holds its own connections. It checks the provider, model, endpoint, account, connection age, and request history before reuse. If a session's connection is busy, another request uses a separate connection. `closeAll()` closes the cache's connections at worker shutdown.

`Result\CancellableRawResultInterface::abort(): void` is the host cancellation contract. `RawWebSocketResult` implements it. Abandoning or failing a stream invalidates its cached entry. A completed stream can retain its connection for compatible continuation.

Pass an `Amp\Cancellation` in the `CodexWebSocketModelClient::CANCELLATION` request option (`codex_cancellation`) to interrupt pending WebSocket connect, send, receive, and message buffering. The bridge consumes this option before serialization. Run cancellation propagates as `Amp\CancelledException`, not an I/O timeout. Cancelling a send closes the socket and joins the writer without retrying the request. Cancellation invalidates cached connections and their continuation state.

Custom `CodexWebSocketConnectorInterface` implementations must accept the fourth argument, `?Amp\Cancellation $cancellation = null`, and pass it to their pending connection operation. Hosts own their cancellation source and must release polling or subscription resources when the invocation ends.

Structured transport logs omit raw prompts, tool content, and bearer tokens. `Error\ProviderErrorFormatter` bounds provider diagnostics. Provider error messages can still contain provider-supplied text.

`CodexWebSocketContinuationMismatchException::diagnostics` carries the two rejected items for a `prefix_mismatch`, with the existing structural diagnostics and `expected_source` set to `previous_request` or `previous_response`. It does not contain either full history. The host owns logging, redaction, and size limits. The exception message and SDK transport logs remain content-free. Item values can contain conversation text, tool arguments, credentials, or reasoning ciphertext; do not log them without redaction.

## Login and credential storage

`Auth\CodexOAuthConfig` configures `originator`, `displayName`, and `commandName`. OpenAI client ID, endpoints, scopes, and redirect defaults remain Codex-specific. Credentials use the `openai-codex` key.

`Auth\CodexAuthCommand` extends Symfony Console `Command`. It accepts `--refresh`, `--port`, `--timeout`, and `--no-browser`. It uses the input supplied by Console, including programmatic command execution.

`Auth\CodexAuthRecord::toArray()` returns `type`, `access`, `refresh`, `expires`, and `accountId`. `expires` is a Unix timestamp in seconds. `isExpired()` applies a 60-second buffer by default.

`Auth\CodexAuthFileStore` persists credentials at a caller-supplied path without replacing entries for other providers. With a token refresher, `loadCredentials()` refreshes expired credentials under the shared file lock. `loadCredentialsRaw()` never refreshes. Applications may implement `Auth\CodexAuthStorageInterface` for another storage backend.

OAuth, Console, and browser-launch dependencies install with this package.

`Auth\CodexAuthRefreshStorageInterface::refreshWithLock()` serializes an explicit refresh with other writes. The OAuth service delegates to this method when the storage implementation provides it. A basic `CodexAuthStorageInterface` implementation does not provide refresh locking by itself.
