# Changelog

## 0.3.0

- Every scan now sends an `Idempotency-Key`, so a retry after a timeout or a 502, 503 or 504 is not counted against your quota twice. The client makes a random key for each call and reuses it on its own retries; no change is needed in your code.
- New optional `idempotencyKey` option on `scanFile`, `scanBytes`, `scanUrl`, `scanFileAsync` and `scanUrlAsync`, to protect your own retries (a job id, for example). `scanUrl` now takes an options array as its second argument. An invalid key (not 1 to 128 characters from `A-Za-z0-9._:-`) throws `ConfigException` before any request. Do not reuse one key for different files: the service counts a different file under the same key as a new scan.
- See the [Kelpgate client standard](https://github.com/KelpGate/.github/blob/main/docs/client-standard.md#idempotency).

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
