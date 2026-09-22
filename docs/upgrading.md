# Upgrade from the in-tree bridge

This extraction targets Symfony AI `^0.12`. The source baseline is Hatfield commit `2715f242b`. No compatibility aliases for Hatfield classes are provided.

1. Require the package through Composer. Remove the host PSR-4 mapping for `Symfony\AI\Platform\Bridge\OpenAICodex` and delete its in-tree bridge copy.
2. Replace `Ineersa\Platform\Result\CancellableRawResultInterface` imports with `Symfony\AI\Platform\Bridge\OpenAICodex\Result\CancellableRawResultInterface` in cancellation consumers and tests.
3. Pass `originator: 'hatfield'` and `userAgent: 'hatfield'` to `Factory::createProvider()` to retain host identity.
4. Pass `internalOptions: ['hatfield_run_id', 'hatfield_model_ref']` to preserve consumption of host-only request fields. Other request adaptation remains host-owned.
5. Keep the dependency-container-owned WebSocket cache. Keep its worker-shutdown call to `closeAll()`.
6. Replace moved auth imports with `Symfony\AI\Platform\Bridge\OpenAICodex\Auth`. Implement `CodexAuthStorageInterface` on the host store or an adapter.
7. Retain host credential paths and auto-refresh locking. `CodexOAuthConfig::AUTH_FILE` is intentionally absent. Define the path in the host store.
8. Configure `CodexOAuthConfig(originator: 'hatfield')`. Register the package `CodexAuthCommand` with the same config and a `CodexOAuthService` backed by the host store. Remove the old command implementation.
9. Update other callers of the shared `LocalCallbackServer`, `BrowserLauncher`, `ManualCodeParser`, and `CodexOAuthProvider`. These helpers no longer require Hatfield classes.
10. Move bridge tests and pure auth tests to this package. Keep host storage, dependency injection, cancellation-consumer, and worker-shutdown integration tests in the host.

The host's generic bridge can retain its own `ProviderErrorFormatter`. The Codex package owns its formatter and has no reverse dependency on the host.

Run package checks with `castor test`, `castor phpstan`, and `castor cs-check`. Run the host's required integration checks after updating its dependency and wiring.
