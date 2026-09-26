# Upgrade from the in-tree bridge

Use this guide when replacing Hatfield's in-tree Codex bridge with the Composer package. The package supports Symfony AI `^0.12` and `^0.13`.

1. Require the package through Composer. Remove the host PSR-4 mapping for `Symfony\AI\Platform\Bridge\OpenAICodex` and delete its in-tree bridge copy.
2. Replace `Ineersa\Platform\Result\CancellableRawResultInterface` imports with `Symfony\AI\Platform\Bridge\OpenAICodex\Result\CancellableRawResultInterface` in cancellation consumers and tests.
3. Pass `originator: 'hatfield'` and `userAgent: 'hatfield'` to `Factory::createProvider()` to retain host identity.
4. Pass `internalOptions: ['hatfield_run_id', 'hatfield_model_ref']` to preserve consumption of host-only request fields. Other request adaptation remains host-owned.
5. Keep the dependency-container-owned WebSocket cache. Keep its worker-shutdown call to `closeAll()`.
6. Replace moved auth imports with `Symfony\AI\Platform\Bridge\OpenAICodex\Auth`. Replace Hatfield's `CodexAuthStorage` with the package's `CodexAuthFileStore`.
7. Pass the existing credential path, shared `LockFactory`, and `CodexTokenRefresher` to the file store. Use `loadCredentials()` for expiry-aware reads. Keep Grok on the same lock backend and location when both providers share a file.
8. Configure `CodexOAuthConfig(originator: 'hatfield')`. Use that config for the token refresher, OAuth service, and command. Alias `CodexAuthStorageInterface` to the package file store and remove the old command implementation.
9. Update other callers of the shared `LocalCallbackServer`, `BrowserLauncher`, `ManualCodeParser`, and `CodexOAuthProvider`. These helpers no longer require Hatfield classes.
10. Remove `--auth-profile` options and `auth_key` settings. Codex uses the `openai-codex` entry, and Grok uses `grok-cli`. Sign in again if credentials exist only under a profile key.
11. Keep bridge and file-store tests in the package. Keep tests for application wiring, shared Codex/Grok storage, cancellation, and worker shutdown in Hatfield.

The host's generic bridge can retain its own `ProviderErrorFormatter`. The Codex package owns its formatter and has no reverse dependency on the host.

Run package checks with `castor test`, `castor phpstan`, and `castor cs-check`. Run the host's required integration checks after updating its dependency and wiring.
