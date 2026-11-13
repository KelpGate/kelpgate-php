# Kelpgate Client for PHP

**Check every upload for malware before your app stores it.**

PHP client for the [Kelpgate](https://kelpgate.cloud) malware-scanning API. Scan uploaded files or remote URLs, get a verdict, and keep infected files out of your app. It has no runtime dependencies beyond `ext-curl` and `ext-json`, and works in plain PHP, Laravel, Symfony, WordPress and Drupal.

> This package is open source under the MIT license. It is a client for the Kelpgate scanning service, a hosted commercial product that is not part of this package. You need a Kelpgate account to use it.

## Contents

[Why Kelpgate](#why-kelpgate) · [How it works](#how-it-works) · [Install](#install) · [Quick start](#quick-start) · [Scanning](#scanning) · [Errors](#errors) · [Options](#options) · [Failure policy](#failure-policy) · [Security and privacy](#security-and-privacy) · [Compatibility](#compatibility) · [Verify your setup](#verify-your-setup) · [Troubleshooting](#troubleshooting) · [Support and versioning](#support-and-versioning) · [Contributing and security](#contributing-and-security) · [License](#license)

## Why Kelpgate

| | |
| --- | --- |
| **One call per file** | Send a path, a stream, bytes or a URL and get a verdict back. An infected file is a normal result, not an exception. |
| **Credentials handled for you** | The OAuth 2.0 access token is fetched, cached and renewed before it expires. Plug in a persistent cache to share one token across requests. |
| **Predictable failure behavior** | Typed exceptions for quota, size, file type, rate limits and network problems. You choose what happens when the scanner cannot answer: block or allow. |
| **Safe by default** | API and token URLs must use HTTPS, redirects are never followed, and secrets never appear in `var_dump` or errors. |
| **No dependencies, any HTTP layer** | The default transport uses cURL and streams uploads from disk. Swap in your own transport to use WordPress's HTTP API or a PSR-18 client. |
| **Same names everywhere** | The methods, errors and settings match the Node.js and Python clients, the WordPress plugin and the Drupal module. See the [client standard](https://github.com/KelpGate/.github/blob/main/docs/client-standard.md). |

## How it works

1. The client obtains an OAuth 2.0 access token with your client credentials. Tokens are cached and renewed shortly before they expire.
2. The file is sent to the scanning API over HTTPS.
3. The API answers with a verdict. A clean file and an infected file (HTTP 406) both come back as a `ScanResult` with `infected` set to `false` or `true`. Anything that stops a verdict, such as an exhausted quota or an unreachable service, is thrown as a typed exception. Your code decides what to do next.

## Install

```bash
composer require kelpgate/kelpgate-php
```

You need an API client from the [Kelpgate console](https://console.kelpgate.cloud): a client ID and secret.

## Quick start

```php
use Kelpgate\KelpgateClient;

$kelpgate = KelpgateClient::fromEnv();          // reads the KELPGATE_* variables below

$result = $kelpgate->scanFile($_FILES['upload']['tmp_name'], ['filename' => $_FILES['upload']['name']]);

if ($result->infected) {
    http_response_code(422);
    exit('Upload rejected: ' . $result->signature);
}
```

Set these environment variables (the endpoints default to Kelpgate's production service):

| Variable | Meaning |
| --- | --- |
| `KELPGATE_CLIENT_ID`, `KELPGATE_CLIENT_SECRET` | OAuth 2.0 client credentials |
| `KELPGATE_TOKEN` | A static bearer token, instead of client credentials |
| `KELPGATE_API_URL` | API base URL, default `https://api.kelpgate.cloud` |
| `KELPGATE_TOKEN_URL` | Token endpoint, default `https://auth.kelpgate.cloud/oauth2/token` |

To make an infected upload throw instead, use `assertClean()`:

```php
use Kelpgate\Exception\InfectedException;

try {
    $kelpgate->scanFile($path)->assertClean();
} catch (InfectedException $e) {
    // $e->getSignature() is the detection name
}
```

## Scanning

| Method | What it does |
| --- | --- |
| `scanFile($pathOrStream, ['filename' => ...])` | Scan a file on disk or a stream |
| `scanBytes($bytes, ['filename' => ...])` | Same, for bytes in memory |
| `scanUrl($url)` | Kelpgate fetches the URL and scans it |
| `scanFileAsync($pathOrStream, ['webhookUrl' => ...])`, `scanUrlAsync($url, ['webhookUrl' => ...])` | Start an asynchronous scan and get an `AcceptedScan` with a `scanId` |
| `getAsyncResult($scanId, ['kind' => 'file'\|'url'])` | A `PendingScan` until the verdict is ready, then a `ScanResult` |
| `waitForResult($scanId, ['intervalMs' => 1000, 'timeout' => 120])` | Poll until the scan finishes, or throw a `TimeoutException` |
| `health()` | Service status. Needs no credentials |

A `ScanResult` has `infected`, `status`, `signature`, `sha256`, `ruleAuthors`, `scanId`, `requestId` and the full response in `raw`. Two flags are worth checking: `policyRejected` means the file was refused by a policy such as an encrypted archive, and `degraded` means an engine did not finish, so coverage was reduced.

### Retries and your quota

Every scan call sends an `Idempotency-Key`. The client makes a random one for each call and reuses it on its own retries, so a retry after a timeout or a 502, 503 or 504 is not counted against your quota twice. You do not have to do anything.

Pass `idempotencyKey` to protect retries you make yourself, for example one key per upload job:

```php
$result = $kelpgate->scanFile($path, ['idempotencyKey' => 'job-' . $job->id]);
```

A key is 1 to 128 characters from `A-Za-z0-9._:-` (anything else throws `ConfigException`). The service ties a key to the file: the same key on a different file is counted as a new scan, so never reuse one key for different uploads.

## Errors

Every exception extends `Kelpgate\Exception\KelpgateException` and carries `getErrorCode()`, `getStatus()`, `getRequestId()` and `getBody()`.

| Exception | When |
| --- | --- |
| `ConfigException` | Wrong options, before any request |
| `AuthException` | Credentials rejected (401), not allowed (403), or no token |
| `QuotaExceededException` | The plan's scan quota is used up (402); has `getQuota()`, `getUsage()`, `getUpgradeUrl()` |
| `PayloadTooLargeException` | File over the plan limit (413) or the client's `maxBytes` |
| `UnsupportedFileException` | File type not allowed, or an encrypted archive (415) |
| `RateLimitedException` | Too many scans at once (429); has `getRetryAfterSeconds()` |
| `ScanException` | The request was invalid (400) or the file could not be parsed (412) |
| `ServerException` | The service failed (5xx) |
| `NetworkException`, `TimeoutException` | The service could not be reached, or the request timed out |
| `InfectedException` | Thrown by `assertClean()` |

## Options

```php
$kelpgate = new KelpgateClient([
    'apiUrl' => 'https://api.kelpgate.cloud',
    'tokenUrl' => 'https://auth.kelpgate.cloud/oauth2/token',
    'clientId' => '...',
    'clientSecret' => '...',
    'timeout' => 60,          // seconds per request
    'maxRetries' => 2,        // retries on network errors, 429, 502, 503 and 504
    'retryDelayMs' => 500,    // base delay, doubled on each retry
    'maxBytes' => 134217728,  // largest file the client will send
]);
```

| Option | Default | Meaning |
| --- | --- | --- |
| `apiUrl` | none | Base URL of the API. HTTPS only (plain HTTP for `localhost`) |
| `tokenUrl`, `clientId`, `clientSecret` | none | OAuth 2.0 client credentials |
| `bearerToken` | none | A static token, instead of client credentials |
| `scope` | `scan` | OAuth 2.0 scope |
| `timeout` | `60` | Seconds per request |
| `maxRetries`, `retryDelayMs` | `2`, `500` | Retry policy; `Retry-After` is honored up to 30 seconds |
| `maxBytes` | 128 MiB | Largest file the client will send |
| `transport` | cURL | Your own `Kelpgate\Http\Transport`, for another HTTP layer |
| `tokenCache` | in memory | Your own `Kelpgate\Auth\TokenCache`, to reuse one token across requests |

PHP normally starts fresh for each web request, so the default in-memory cache fetches a new token every time. For busy sites, pass a cache that persists (APCu, your framework's cache, a WordPress transient) by implementing the three-method `TokenCache` interface.

## Failure policy

When the scanner cannot give a verdict, the client throws a typed exception. What your application does with it is your choice:

| Situation | Exception | Block (safer) | Allow (stays available) |
| --- | --- | --- | --- |
| Scanner unreachable, timed out or 5xx after retries | `NetworkException`, `TimeoutException`, `ServerException` | Reject the upload, show "try again" | Accept the upload unscanned |
| Authentication fails | `AuthException` | Reject, and alert your team | Accept |
| Plan quota used up | `QuotaExceededException` | Reject | Accept |
| File over the plan limit or `maxBytes` | `PayloadTooLargeException` | Reject | Accept |
| File type not allowed | `UnsupportedFileException` | Reject | Reject: this is a policy decision |

```php
try {
    $result = $kelpgate->scanFile($path);
} catch (\Kelpgate\Exception\KelpgateException $e) {
    error_log('Kelpgate: ' . $e->getErrorCode() . ' ' . $e->getMessage());
    // block: refuse the upload. allow: carry on.
}
```

Choose block where stopping malware matters more than availability, and allow where uploads must keep working during an outage.

## Security and privacy

- **What leaves your server:** the uploaded file and its file name, sent over HTTPS to the API URL you configure. Nothing else about your application is sent.
- **Transport:** API and token endpoints must use HTTPS (plain HTTP is accepted only for `localhost`). Redirects are never followed.
- **Secrets:** the client secret never appears in `var_dump`, `print_r` or error messages. Keep it in an environment variable or a secrets manager, not in source control.
- **Token handling:** access tokens are kept in the cache you provide, in memory by default. A rejected token is discarded and retried once.
- **Temporary files:** streams and byte strings are written to a temporary file for the upload and deleted afterwards. Files on disk are read in place.
- **Data kept by the service:** files that scan clean are kept briefly and infected files are quarantined, according to your Kelpgate plan. That is the service's behavior, not this package's.

## Compatibility

PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4, with `ext-curl` (for the default transport) and `ext-json`. The test suite runs on every one of those versions.

## Verify your setup

Download the standard EICAR test file from [eicar.org](https://www.eicar.org/download-anti-malware-testfile/). It is a harmless file that every scanner treats as a virus.

```php
$result = KelpgateClient::fromEnv()->scanFile('eicar.com');
var_dump($result->infected, $result->signature);   // true, "Eicar-Signature" or similar
```

Then scan any ordinary file and confirm `infected` is `false`. `KelpgateClient::fromEnv()->health()->ok` checks that the service is reachable without any credentials.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `ConfigException`: must use https | `apiUrl` or `tokenUrl` is not HTTPS. Plain HTTP works only for `localhost`. |
| `ConfigException`: ext-curl is not available | Install `ext-curl`, or pass your own `transport`. |
| `AuthException` with code `TOKEN_REQUEST_FAILED` | The client ID or secret is wrong, or the API client does not have the `scan` scope. |
| `AuthException` with status 403 | The organization is suspended, or the API client is not linked to an organization. |
| `QuotaExceededException` | The plan's scan quota is used up. The exception carries an upgrade URL. |
| `RateLimitedException`, or slow scans under load | Too many scans at once. The client retries and honors `Retry-After`; lower your concurrency. |
| `PayloadTooLargeException` | The file is over the plan limit or the client's `maxBytes`. |
| `TimeoutException` on large files | Raise `timeout`, or use `scanFileAsync` and `waitForResult`. |
| A new token request on every web request | Pass a persistent `tokenCache`. |
| Clean results with `degraded` set | An engine did not finish for that scan. The result is still valid; coverage was reduced. |

## Support and versioning

This project follows [Semantic Versioning](https://semver.org): breaking changes only in major versions, new features in minor versions, fixes in patch versions. Releases are tagged on GitHub, and user-visible changes are listed in the [changelog](CHANGELOG.md).

Fixes, including security fixes, go into the latest release only, so upgrade to receive them. The tested runtime versions are listed under Compatibility. The client talks to version 1 of the Kelpgate API (`/api/v1`).

For help, see [getting help](https://github.com/KelpGate/.github/blob/main/SUPPORT.md) or email support@kelpgate.cloud.

## Contributing and security

Bug reports and pull requests are welcome; see the [contributing guide](https://github.com/KelpGate/.github/blob/main/CONTRIBUTING.md). To report a vulnerability, email security@kelpgate.cloud or follow the [security policy](https://github.com/KelpGate/.github/blob/main/SECURITY.md). For help, see [getting help](https://github.com/KelpGate/.github/blob/main/SUPPORT.md).

```bash
composer install
composer check    # phpcs, phpstan and phpunit
```

## License

MIT. See [LICENSE](LICENSE). You can use this package in open-source and proprietary software alike. The Kelpgate scanning service it connects to is a hosted commercial product with its own terms.
