# OpenAI Codex for Symfony AI

A Composer library for the ChatGPT subscription Codex Responses endpoint. Requires PHP 8.5 or later and Symfony AI `^0.12`. This is an independent package, not an official Symfony or OpenAI bridge.

The bridge supports SSE, WebSocket, and session-cached WebSocket transports. The optional `Auth` component provides Codex OAuth PKCE login and refresh. Neither the transport nor credential records depend on Hatfield.

- [Connect an application](docs/usage.md)
- [Register the OAuth command](docs/auth.md)
- [API and ownership reference](docs/reference.md)
- [Upgrade from the in-tree bridge](docs/upgrading.md)

## Development

Install Composer dependencies, then use Castor for package QA:

```sh
composer install
castor test
castor phpstan
castor cs-check
```

`castor cs-fix` applies the coding standard. Tests use mocked provider responses and local sockets. They do not read stored credentials, launch a browser, or contact OpenAI. PHPUnit writes `var/reports/junit.xml`. PHPStan analyzes source and tests at level 6.

The package is distributed under the [MIT license](LICENSE).
