# Changelog

## 0.2.0

- The license changed from GPL-2.0-or-later to MIT, so the package can be used in proprietary software as well as open-source software. Versions published earlier remain available under the GPL. The WordPress plugin and Drupal module stay GPL, as WordPress.org and Drupal.org require.
- No code changes.

## 0.1.0

First release.

- `KelpgateClient`: scan files (path, stream, bytes) and URLs, sync or async, with `waitForResult`.
- OAuth 2.0 client-credentials with a cached token, renewed before it expires, and a pluggable `TokenCache`.
- Retries on network errors, 429, 502, 503 and 504; typed exceptions for every API status.
- A pluggable `Transport` (cURL by default) so the client can run on WordPress's HTTP API or a PSR-18 client.
- Names, errors and settings match the other Kelpgate clients ([client standard](https://github.com/KelpGate/.github/blob/main/docs/client-standard.md)).
- PHP 7.4 to 8.4; no runtime dependencies beyond `ext-json` (and `ext-curl` for the default transport).
