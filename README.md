# Kelpgate Client for PHP

[![Release](https://img.shields.io/github/v/release/KelpGate/kelpgate-php)](https://github.com/KelpGate/kelpgate-php/releases)
[![CI](https://github.com/KelpGate/kelpgate-php/actions/workflows/ci.yml/badge.svg)](https://github.com/KelpGate/kelpgate-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Official PHP client for the [Kelpgate](https://kelpgate.cloud) malware-scanning API. It scans files, streams, strings and URLs, manages OAuth 2.0 access tokens, retries transient failures idempotently and maps API responses to typed results and exceptions. It requires no Composer dependencies; the default transport uses `ext-curl` and streams uploads from disk. The HTTP transport and token cache are replaceable, so the client integrates with framework HTTP layers and caches, including Laravel, Symfony, WordPress and Drupal.

The package is open source under the MIT licence. The Kelpgate scanning service is a commercial hosted product; using it requires a Kelpgate account.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Scan results](#scan-results)
- [Exceptions](#exceptions)
- [Retries and idempotency](#retries-and-idempotency)
- [Token caching](#token-caching)
- [Custom transport](#custom-transport)
- [Failure policy](#failure-policy)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [Compatibility and versioning](#compatibility-and-versioning)
- [Development](#development)
- [Support](#support)
- [Licence](#licence)

## Requirements

- PHP 7.4 or later with `ext-json`.
- `ext-curl` for the default transport.
- A Kelpgate API client (client ID and secret) created in the [Kelpgate Console](https://console.kelpgate.cloud).

## Installation

The package is distributed from this repository; Packagist publication is pending. Register the repository with Composer, then require the package:

```bash
composer config repositories.kelpgate vcs https://github.com/KelpGate/kelpgate-php
composer require kelpgate/client:^0.3
```

## Configuration

```php
use Kelpgate\KelpgateClient;

$kelpgate = new KelpgateClient([
    'apiUrl' => 'https://api.kelpgate.cloud',
    'tokenUrl' => 'https://auth.kelpgate.cloud/oauth2/token',
    'clientId' => getenv('KELPGATE_CLIENT_ID'),
    'clientSecret' => getenv('KELPGATE_CLIENT_SECRET'),
]);
```

| Option | Default | Description |
|---|---|---|
| `apiUrl` | required | Base URL of the Kelpgate API |
| `tokenUrl` | required with client credentials | OAuth 2.0 token endpoint |
| `clientId`, `clientSecret` | | Client credentials |
| `bearerToken` | | Static access token, used instead of client credentials |
| `scope` | `scan` | OAuth 2.0 scope requested |
| `timeout` | `60` | Per-request timeout in seconds |
| `maxRetries` | `2` | Retries for network errors and HTTP 429, 502, 503 and 504 |
| `retryDelayMs` | `500` | Base delay for exponential backoff; `Retry-After` is honoured up to 30 seconds |
| `maxBytes` | 128 MiB | Largest file the client sends; larger files fail before upload |
| `transport` | cURL | Implementation of `Kelpgate\Http\Transport` |
| `tokenCache` | in-memory | Implementation of `Kelpgate\Auth\TokenCache` |

`apiUrl` and `tokenUrl` must use HTTPS; plain HTTP is accepted only for `localhost`.

`KelpgateClient::fromEnv()` creates a client from environment variables:

| Variable | Default |
|---|---|
| `KELPGATE_CLIENT_ID`, `KELPGATE_CLIENT_SECRET` | |
| `KELPGATE_TOKEN` | Static token, instead of client credentials |
| `KELPGATE_API_URL` | `https://api.kelpgate.cloud` |
| `KELPGATE_TOKEN_URL` | `https://auth.kelpgate.cloud/oauth2/token` |

## Usage

```php
use Kelpgate\KelpgateClient;
use Kelpgate\Exception\InfectedException;

$kelpgate = KelpgateClient::fromEnv();

$result = $kelpgate->scanFile($_FILES['upload']['tmp_name'], [
    'filename' => $_FILES['upload']['name'],
]);

if ($result->infected) {
    // reject the upload
}

// Alternatively, throw InfectedException for an infected file:
try {
    $kelpgate->scanFile($path)->assertClean();
} catch (InfectedException $e) {
    $signature = $e->getSignature();
}
```

| Method | Description |
|---|---|
| `scanFile($pathOrStream, $options)` | Scan a file path or stream resource |
| `scanBytes($bytes, $options)` | Scan in-memory content |
| `scanUrl($url, $options)` | Have the service fetch and scan a URL |
| `scanFileAsync($pathOrStream, $options)`, `scanUrlAsync($url, $options)` | Submit a background scan; returns `AcceptedScan` with `scanId`. Accepts `webhookUrl`. |
| `getAsyncResult($scanId, ['kind' => 'file'\|'url'])` | Returns `PendingScan` until the result is available, then `ScanResult` |
| `waitForResult($scanId, ['intervalMs' => 1000, 'timeout' => 120])` | Poll until the result is available; throws `TimeoutException` on expiry |
| `health()` | Service readiness; requires no credentials |

Upload methods accept `filename` and `idempotencyKey`.

## Scan results

An infected file is a result, not an exception. `ScanResult` exposes:

| Property | Type | Description |
|---|---|---|
| `infected` | `bool` | `true` for malware or a policy rejection |
| `status` | `string` | `CLEAN` or `INFECTED` |
| `signature` | `string` | Detection names, joined with ` \| ` |
| `policyRejected` | `bool` | Rejected by policy (for example an encrypted archive) rather than detected |
| `degraded` | `bool` | An engine did not complete; coverage was reduced |
| `sha256` | `?string` | SHA-256 of the scanned content |
| `ruleAuthors` | `string[]` | Authors of matching detection rules. Preserve them when displaying or forwarding a detection, as required by the Detection Rule License. |
| `scanId`, `requestId` | `?string` | Identifiers; quote `requestId` in support requests |
| `filename`, `timestamp`, `s3Path`, `downloadUrl` | `?string` | As returned by the service |
| `raw` | `array` | Response body as received |

Integrations that require full coverage should treat `degraded` as not verified.

## Exceptions

All exceptions extend `Kelpgate\Exception\KelpgateException` and expose `getStatus()`, `getErrorCode()`, `getRequestId()` and `getBody()`.

| Exception | Condition |
|---|---|
| `ConfigException` | Invalid options or idempotency key; thrown before any request |
| `AuthException` | Credentials rejected (401), organization or client not permitted (403), or no token available |
| `QuotaExceededException` | Monthly quota exhausted (402). Exposes `getQuota()` and `getUpgradeUrl()`. |
| `PayloadTooLargeException` | File over the plan or scanner limit (413), or over `maxBytes` |
| `UnsupportedFileException` | File type not permitted, or archive rejected by policy (415) |
| `RateLimitedException` | Concurrency limit reached (429). Exposes `getRetryAfterSeconds()`. |
| `ScanException` | Invalid request (400), or content that could not be parsed (412) |
| `ServerException` | HTTP 5xx after retries |
| `NetworkException`, `TimeoutException` | The service could not be reached or did not respond in time |
| `InfectedException` | Thrown by `assertClean()`; exposes `getResult()` and `getSignature()` |
| `KelpgateException` | Any other status, including 404 and 422 |

## Retries and idempotency

Every scan request carries an `Idempotency-Key`. The client generates one per call and reuses it for its own retries, so a retried scan is not counted twice against the quota. A rejected access token is discarded and the request retried once with a new token.

Supply a key to make retries across requests or job re-runs idempotent:

```php
$kelpgate->scanFile($path, ['idempotencyKey' => 'upload-' . $job->id]);
```

Keys are 1 to 128 characters from `A-Z a-z 0-9 . _ : -`. The service binds a key to the request content; reusing a key for a different file is counted as a new scan.

## Token caching

PHP processes typically serve one request, so the default in-memory cache requests a new access token for every request. Production deployments should supply a persistent cache by implementing `Kelpgate\Auth\TokenCache`:

```php
interface TokenCache
{
    public function get(string $key): ?array;
    public function set(string $key, string $token, int $expiresAt): void;
    public function delete(string $key): void;
}
```

Back it with APCu, Redis, the framework cache or WordPress transients, and pass it as `tokenCache`.

## Custom transport

`Kelpgate\Http\Transport` abstracts HTTP. Implement it to route requests through a framework HTTP client, a PSR-18 client or the WordPress HTTP API, and pass the implementation as `transport`. The bundled `CurlTransport` streams uploads from disk and does not follow redirects.

## Failure policy

When the service cannot return a verdict, the client throws. The application decides whether to block or accept the upload:

| Condition | Exception | Block | Allow |
|---|---|---|---|
| Service unreachable, timed out, or 5xx after retries | `NetworkException`, `TimeoutException`, `ServerException` | Reject; ask the user to retry | Accept unscanned |
| Authentication failed | `AuthException` | Reject and alert operations | Accept unscanned |
| Quota exhausted | `QuotaExceededException` | Reject | Accept unscanned |
| File too large | `PayloadTooLargeException` | Reject | Accept unscanned |
| File type not permitted | `UnsupportedFileException` | Reject | Reject (policy decision) |

```php
try {
    $result = $kelpgate->scanFile($path);
} catch (\Kelpgate\Exception\KelpgateException $e) {
    error_log(sprintf('Kelpgate %s: %s', $e->getErrorCode(), $e->getMessage()));
    // apply the chosen policy
}
```

Use block where preventing malware takes priority over availability, and allow where uploads must continue during a service outage.

## Security

| Aspect | Behaviour |
|---|---|
| Data sent | The file content and its name, over HTTPS, to the configured `apiUrl` only |
| Transport | HTTPS required except for `localhost`; redirects are never followed |
| Credentials | Never included in `var_dump`, `print_r` or exception messages |
| Tokens | Stored only in the configured token cache; in memory by default |
| Local storage | Streams and strings are written to a temporary file for upload and deleted afterwards; files on disk are read in place |
| Service retention | Clean files are retained briefly and infected files quarantined, according to the Kelpgate plan |

Report vulnerabilities according to the [security policy](https://github.com/KelpGate/.github/blob/main/SECURITY.md).

## Troubleshooting

Verify an installation with the [EICAR test file](https://www.eicar.org/download-anti-malware-testfile/), which every scanner reports as infected:

```php
$result = KelpgateClient::fromEnv()->scanFile('eicar.com');
var_dump($result->infected);   // bool(true)
```

| Symptom | Cause |
|---|---|
| `ConfigException: ... must use https` | `apiUrl` or `tokenUrl` is not HTTPS |
| `ConfigException: ext-curl is not available` | Install `ext-curl`, or supply a `transport` |
| `AuthException` with code `TOKEN_REQUEST_FAILED` | Invalid client ID or secret, or the client lacks the `scan` scope |
| `AuthException` with status 403 | Organization suspended, or client not linked to an organization |
| `RateLimitedException` | Too many concurrent scans; the client honours `Retry-After`. Reduce concurrency. |
| `TimeoutException` on large files | Increase `timeout`, or use `scanFileAsync` with `waitForResult` |
| A token request on every web request | Supply a persistent `tokenCache` |
| `degraded` is `true` | An engine did not complete for that scan |

## Compatibility and versioning

| Component | Supported |
|---|---|
| PHP | 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 (tested in CI) |
| Extensions | `ext-json`; `ext-curl` for the default transport |
| Kelpgate API | Version 1 (`/api/v1`) |

The package follows [Semantic Versioning](https://semver.org); fixes, including security fixes, are released for the latest version only. Changes are listed in the [changelog](CHANGELOG.md). The organisation-wide policy is in [versioning and releases](https://github.com/KelpGate/.github/blob/main/docs/versioning-and-releases.md).

## Development

```bash
composer install
composer check    # PHP_CodeSniffer, PHPStan and PHPUnit
```

Contributions follow the [contributing guide](https://github.com/KelpGate/.github/blob/main/CONTRIBUTING.md).

## Support

See [getting help](https://github.com/KelpGate/.github/blob/main/SUPPORT.md) or contact support@kelpgate.cloud.

## Licence

MIT. See [LICENSE](LICENSE). The Kelpgate scanning service is subject to its own terms.
