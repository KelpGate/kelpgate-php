<?php

declare(strict_types=1);

namespace Kelpgate;

use Kelpgate\Auth\ArrayTokenCache;
use Kelpgate\Auth\TokenCache;
use Kelpgate\Exception\AuthException;
use Kelpgate\Exception\ConfigException;
use Kelpgate\Exception\KelpgateException;
use Kelpgate\Exception\NetworkException;
use Kelpgate\Exception\PayloadTooLargeException;
use Kelpgate\Exception\QuotaExceededException;
use Kelpgate\Exception\RateLimitedException;
use Kelpgate\Exception\ScanException;
use Kelpgate\Exception\ServerException;
use Kelpgate\Exception\TimeoutException;
use Kelpgate\Exception\UnsupportedFileException;
use Kelpgate\Http\CurlTransport;
use Kelpgate\Http\Request;
use Kelpgate\Http\Response;
use Kelpgate\Http\Transport;
use Kelpgate\Http\Upload;

/**
 * Client for the Kelpgate malware-scanning API.
 *
 *     $kelpgate = KelpgateClient::fromEnv();
 *     $result = $kelpgate->scanFile('/tmp/upload.pdf');
 *     if ($result->infected) {
 *         echo 'Rejected: ' . $result->signature;
 *     }
 */
final class KelpgateClient
{
    public const VERSION = '0.3.0';

    private const DEFAULT_MAX_BYTES = 134217728; // 128 MiB, a little above the largest plan limit
    private const REFRESH_AHEAD = 120;           // renew a token this many seconds before it expires
    private const RETRY_AFTER_CAP_MS = 30000;

    /** @var string */
    private $apiUrl;

    /** @var string|null */
    private $tokenUrl;

    /** @var string|null */
    private $clientId;

    /** @var string|null */
    private $clientSecret;

    /** @var string|null */
    private $bearerToken;

    /** @var string */
    private $scope;

    /** @var float */
    private $timeout;

    /** @var int */
    private $maxRetries;

    /** @var int */
    private $retryDelayMs;

    /** @var int */
    private $maxBytes;

    /** @var Transport */
    private $transport;

    /** @var TokenCache */
    private $tokenCache;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param array{
     *     apiUrl?: string,
     *     tokenUrl?: string,
     *     clientId?: string,
     *     clientSecret?: string,
     *     bearerToken?: string,
     *     scope?: string,
     *     timeout?: int|float,
     *     maxRetries?: int,
     *     retryDelayMs?: int,
     *     maxBytes?: int,
     *     transport?: Transport,
     *     tokenCache?: TokenCache,
     *     sleeper?: callable(int): void
     * } $options `apiUrl` is required. Pass `bearerToken`, or `tokenUrl` with `clientId` and `clientSecret`.
     *     `timeout` is in seconds, `retryDelayMs` is the base delay between retries.
     *
     * @throws ConfigException
     */
    public function __construct(array $options)
    {
        $this->apiUrl = self::checkUrl('apiUrl', (string) ($options['apiUrl'] ?? ''));
        $this->bearerToken = isset($options['bearerToken']) && $options['bearerToken'] !== ''
            ? (string) $options['bearerToken']
            : null;
        if ($this->bearerToken === null) {
            $this->tokenUrl = self::checkUrl('tokenUrl', (string) ($options['tokenUrl'] ?? ''));
            if (empty($options['clientId']) || empty($options['clientSecret'])) {
                throw new ConfigException('clientId and clientSecret are required (or pass bearerToken)');
            }
            $this->clientId = (string) $options['clientId'];
            $this->clientSecret = (string) $options['clientSecret'];
        }
        $this->scope = isset($options['scope']) && $options['scope'] !== '' ? (string) $options['scope'] : 'scan';
        $this->timeout = isset($options['timeout']) && $options['timeout'] > 0 ? (float) $options['timeout'] : 60.0;
        $this->maxRetries = isset($options['maxRetries']) ? max(0, (int) $options['maxRetries']) : 2;
        $this->retryDelayMs = isset($options['retryDelayMs']) ? max(0, (int) $options['retryDelayMs']) : 500;
        $this->maxBytes = isset($options['maxBytes']) && $options['maxBytes'] > 0
            ? (int) $options['maxBytes']
            : self::DEFAULT_MAX_BYTES;
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->tokenCache = $options['tokenCache'] ?? new ArrayTokenCache();
        $this->sleeper = $options['sleeper'] ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * Builds a client from `KELPGATE_*` environment variables, with the production endpoints as defaults:
     * KELPGATE_API_URL, KELPGATE_TOKEN_URL, KELPGATE_CLIENT_ID, KELPGATE_CLIENT_SECRET and KELPGATE_TOKEN.
     *
     * @param array<string, string>|null $env       Defaults to the process environment.
     * @param array<string, mixed>       $overrides Any constructor option; it wins over the environment.
     *
     * @throws ConfigException
     */
    public static function fromEnv(?array $env = null, array $overrides = []): self
    {
        $get = static function (string $name) use ($env): string {
            if ($env !== null) {
                return isset($env[$name]) ? (string) $env[$name] : '';
            }
            $value = getenv($name);

            return $value === false ? '' : $value;
        };

        $options = [
            'apiUrl' => $get('KELPGATE_API_URL') ?: 'https://api.kelpgate.cloud',
            'tokenUrl' => $get('KELPGATE_TOKEN_URL') ?: 'https://auth.kelpgate.cloud/oauth2/token',
        ];
        $credentials = [
            'clientId' => 'KELPGATE_CLIENT_ID',
            'clientSecret' => 'KELPGATE_CLIENT_SECRET',
            'bearerToken' => 'KELPGATE_TOKEN',
        ];
        foreach ($credentials as $option => $name) {
            if ($get($name) !== '') {
                $options[$option] = $get($name);
            }
        }

        return new self($overrides + $options);
    }

    /** Keeps credentials out of var_dump(), print_r() and error logs. */
    public function __debugInfo(): array
    {
        return ['apiUrl' => $this->apiUrl, 'scope' => $this->scope];
    }

    // ---- public API

    /**
     * Scans a file on disk, or a readable stream.
     *
     * @param string|resource|BytesSource $source A file path, or a stream resource.
     * @param array{filename?: string, idempotencyKey?: string} $options `idempotencyKey` makes retries safe:
     *     the service counts a request repeated under the same key only once. By default a random key is
     *     used for each call and reused by the client's own retries.
     *
     * @throws KelpgateException
     */
    public function scanFile($source, array $options = []): ScanResult
    {
        return $this->scanResult($this->upload('/api/v1/scan/file', $source, $options));
    }

    /**
     * Scans bytes you already have in memory.
     *
     * @param array{filename?: string, idempotencyKey?: string} $options
     *
     * @throws KelpgateException
     */
    public function scanBytes(string $bytes, array $options = []): ScanResult
    {
        return $this->scanFile(new BytesSource($bytes), $options);
    }

    /**
     * Asks the service to fetch and scan a remote file.
     *
     * @param array{idempotencyKey?: string} $options
     *
     * @throws KelpgateException
     */
    public function scanUrl(string $url, array $options = []): ScanResult
    {
        $r = $this->request('POST', '/api/v1/scan/url', [
            'body' => $this->json(['url' => $url]),
            'headers' => [
                'content-type' => 'application/json',
                'idempotency-key' => $this->idempotencyKey($options),
            ],
        ]);

        return $this->scanResult($r);
    }

    /**
     * Starts an asynchronous scan of a file.
     *
     * @param string|resource                           $source
     * @param array{filename?: string, webhookUrl?: string, idempotencyKey?: string} $options
     *     `webhookUrl` is called when the scan finishes.
     *
     * @throws KelpgateException
     */
    public function scanFileAsync($source, array $options = []): AcceptedScan
    {
        $fields = !empty($options['webhookUrl']) ? ['webhook_url' => (string) $options['webhookUrl']] : [];

        return $this->accepted($this->upload('/api/v1/async-scan/file', $source, $options, $fields));
    }

    /**
     * Starts an asynchronous scan of a remote file.
     *
     * @param array{webhookUrl?: string, idempotencyKey?: string} $options
     *
     * @throws KelpgateException
     */
    public function scanUrlAsync(string $url, array $options = []): AcceptedScan
    {
        $payload = ['url' => $url];
        if (!empty($options['webhookUrl'])) {
            $payload['webhook_url'] = (string) $options['webhookUrl'];
        }

        return $this->accepted($this->request('POST', '/api/v1/async-scan/url', [
            'body' => $this->json($payload),
            'headers' => [
                'content-type' => 'application/json',
                'idempotency-key' => $this->idempotencyKey($options),
            ],
        ]));
    }

    /**
     * Reads an asynchronous result. Returns a PendingScan until the verdict is ready.
     *
     * @param array{kind?: string} $options `kind` is "file" (default) or "url".
     *
     * @return ScanResult|PendingScan
     *
     * @throws KelpgateException
     */
    public function getAsyncResult(string $scanId, array $options = [])
    {
        $kind = isset($options['kind']) && $options['kind'] === 'url' ? 'url' : 'file';
        $r = $this->request('GET', '/api/v1/async-scan/' . $kind . '?scan_id=' . rawurlencode($scanId));
        $data = $r['data'];
        if ($r['status'] === 202 || (isset($data['av-status']) && $data['av-status'] === 'PROCESSING')) {
            return new PendingScan($scanId, $r['requestId']);
        }

        return $this->scanResult($r);
    }

    /**
     * Polls until an asynchronous scan finishes.
     *
     * @param array{kind?: string, intervalMs?: int, timeout?: int|float} $options `timeout` is in seconds.
     *
     * @throws TimeoutException If the scan is not finished in time.
     * @throws KelpgateException
     */
    public function waitForResult(string $scanId, array $options = []): ScanResult
    {
        $interval = isset($options['intervalMs']) ? max(1, (int) $options['intervalMs']) : 1000;
        $limit = isset($options['timeout']) && $options['timeout'] > 0 ? (float) $options['timeout'] : 120.0;
        $deadline = microtime(true) + $limit;
        while (true) {
            $result = $this->getAsyncResult($scanId, $options);
            if ($result instanceof ScanResult) {
                return $result;
            }
            if (microtime(true) + $interval / 1000 > $deadline) {
                throw new TimeoutException(sprintf('Scan %s was not finished after %s seconds', $scanId, $limit));
            }
            ($this->sleeper)($interval);
        }
    }

    /**
     * Checks that the service is up. Needs no credentials.
     *
     * @throws KelpgateException
     */
    public function health(): Health
    {
        $r = $this->request('GET', '/api/v1/health', ['auth' => false]);

        return new Health(
            $r['status'] === 200,
            $r['status'],
            isset($r['data']['signature_version']) ? (string) $r['data']['signature_version'] : null,
            $r['requestId']
        );
    }

    // ---- authentication

    private function tokenCacheKey(): string
    {
        return 'kelpgate_token_' . hash('sha256', $this->tokenUrl . '|' . $this->clientId . '|' . $this->scope);
    }

    private function getToken(bool $forceRefresh = false): string
    {
        if ($this->bearerToken !== null) {
            return $this->bearerToken;
        }
        $key = $this->tokenCacheKey();
        $cached = $this->tokenCache->get($key);
        if (!$forceRefresh && $cached !== null && $cached['expiresAt'] - time() > self::REFRESH_AHEAD) {
            return $cached['token'];
        }
        try {
            $fresh = $this->fetchToken();
        } catch (KelpgateException $e) {
            // renewing early failed but the old token is still good: keep using it
            if (!$forceRefresh && $cached !== null && $cached['expiresAt'] > time()) {
                return $cached['token'];
            }
            throw $e;
        }
        $this->tokenCache->set($key, $fresh['token'], $fresh['expiresAt']);

        return $fresh['token'];
    }

    /** @return array{token: string, expiresAt: int} */
    private function fetchToken(): array
    {
        $basic = base64_encode(rawurlencode((string) $this->clientId) . ':' . rawurlencode((string) $this->clientSecret));
        $request = new Request(
            'POST',
            (string) $this->tokenUrl,
            [
                'authorization' => 'Basic ' . $basic,
                'content-type' => 'application/x-www-form-urlencoded',
                'user-agent' => $this->userAgent(),
                'accept' => 'application/json',
            ],
            http_build_query(['grant_type' => 'client_credentials', 'scope' => $this->scope]),
            null,
            [],
            $this->timeout
        );
        $response = $this->transport->send($request);
        $data = $this->decode($response->body);
        if ($response->status < 200 || $response->status >= 300 || empty($data['access_token'])) {
            $why = $data['error_description'] ?? $data['error'] ?? 'HTTP ' . $response->status;
            unset($data['access_token'], $data['refresh_token']);
            throw new AuthException(
                'Could not get an access token: ' . (is_scalar($why) ? (string) $why : 'unknown error'),
                ['status' => $response->status, 'code' => 'TOKEN_REQUEST_FAILED', 'body' => $data]
            );
        }
        $seconds = isset($data['expires_in']) && (int) $data['expires_in'] > 0 ? (int) $data['expires_in'] : 300;

        return ['token' => (string) $data['access_token'], 'expiresAt' => time() + $seconds];
    }

    // ---- HTTP

    private function userAgent(): string
    {
        return 'kelpgate-php/' . self::VERSION . ' php/' . PHP_VERSION;
    }

    /**
     * Sends a request with auth, one token retry on 401, and bounded retries on transient failures.
     *
     * @param array{
     *     body?: string|null,
     *     headers?: array<string, string>,
     *     auth?: bool,
     *     upload?: Upload,
     *     fields?: array<string, string>
     * } $options
     *
     * @return array{status: int, data: array<string, mixed>, requestId: string|null, response: Response}
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $auth = $options['auth'] ?? true;
        $tokenRetried = false;
        for ($attempt = 0;; $attempt++) {
            $headers = ['user-agent' => $this->userAgent(), 'accept' => 'application/json'];
            foreach (($options['headers'] ?? []) as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
            if ($auth) {
                $headers['authorization'] = 'Bearer ' . $this->getToken();
            }
            $request = new Request(
                $method,
                $this->apiUrl . $path,
                $headers,
                $options['body'] ?? null,
                $options['upload'] ?? null,
                $options['fields'] ?? [],
                $this->timeout
            );
            try {
                $response = $this->transport->send($request);
            } catch (NetworkException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                ($this->sleeper)($this->backoff($attempt, null));
                continue;
            }

            if ($response->status === 401 && $auth && $this->bearerToken === null && !$tokenRetried) {
                $tokenRetried = true; // the token may have been revoked or rotated: get a fresh one once
                $this->tokenCache->delete($this->tokenCacheKey());
                $this->getToken(true);
                continue;
            }
            $retryable = in_array($response->status, [429, 502, 503, 504], true);
            if ($retryable && $attempt < $this->maxRetries) {
                ($this->sleeper)($this->backoff($attempt, $response->header('retry-after')));
                continue;
            }

            return [
                'status' => $response->status,
                'data' => $this->decode($response->body),
                'requestId' => $response->header('x-request-id'),
                'response' => $response,
            ];
        }
    }

    private function backoff(int $attempt, ?string $retryAfter): int
    {
        if ($retryAfter !== null && is_numeric($retryAfter) && (float) $retryAfter >= 0) {
            return (int) min((float) $retryAfter * 1000, self::RETRY_AFTER_CAP_MS);
        }

        $jitterMax = min(100, $this->retryDelayMs);

        return $this->retryDelayMs * (2 ** $attempt) + ($jitterMax > 0 ? random_int(0, $jitterMax) : 0);
    }

    /**
     * @param array{status: int, data: array<string, mixed>, requestId: string|null, response: Response} $r
     */
    private function fail(array $r): KelpgateException
    {
        $d = $r['data'];
        $message = (string) ($d['error'] ?? $d['message'] ?? 'Kelpgate returned HTTP ' . $r['status']);
        $details = ['status' => $r['status'], 'requestId' => $r['requestId'], 'body' => $d];
        switch ($r['status']) {
            case 401:
                return new AuthException($message, $details + ['code' => 'UNAUTHORIZED']);
            case 403:
                return new AuthException($message, $details + ['code' => 'FORBIDDEN']);
            case 402:
                return new QuotaExceededException($message, $details);
            case 413:
                return new PayloadTooLargeException($message, $details);
            case 415:
                return new UnsupportedFileException($message, $details);
            case 429:
                $retryAfter = $r['response']->header('retry-after');

                return new RateLimitedException(
                    $message,
                    $details + ['retryAfterSeconds' => is_numeric($retryAfter) ? (int) $retryAfter : null]
                );
            case 404:
                return new KelpgateException($message, $details + ['code' => 'NOT_FOUND']);
            case 400:
            case 412:
                return new ScanException($message, $details);
            default:
                return $r['status'] >= 500
                    ? new ServerException($message, $details)
                    : new KelpgateException($message, $details);
        }
    }

    // ---- input handling

    /**
     * @param string|resource|BytesSource $source
     * @param array<string, mixed>        $options
     * @param array<string, string>       $fields
     *
     * @return array{status: int, data: array<string, mixed>, requestId: string|null, response: Response}
     */
    private function upload(string $path, $source, array $options = [], array $fields = []): array
    {
        $key = $this->idempotencyKey($options);
        $upload = $this->toUpload($source, isset($options['filename']) ? (string) $options['filename'] : null);
        try {
            return $this->request('POST', $path, [
                'upload' => $upload,
                'fields' => $fields,
                'headers' => ['idempotency-key' => $key],
            ]);
        } finally {
            if ($upload->temporary) {
                @unlink($upload->path);
            }
        }
    }

    /**
     * The Idempotency-Key for one scan call. The service counts a request repeated under the same key
     * only once, so the key is made once per call and reused by every retry of that call. A key you pass
     * is used as given; without one, a random UUID is used.
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigException
     */
    private function idempotencyKey(array $options): string
    {
        if (!isset($options['idempotencyKey'])) {
            return self::uuid4();
        }
        $key = $options['idempotencyKey'];
        if (!is_string($key) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key) !== 1) {
            throw new ConfigException('idempotencyKey must be 1 to 128 characters from A-Z a-z 0-9 . _ : -');
        }

        return $key;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * @param string|resource|BytesSource $source
     *
     * @throws ConfigException
     * @throws PayloadTooLargeException
     */
    private function toUpload($source, ?string $filename): Upload
    {
        if (is_string($source)) {
            if (!is_file($source) || !is_readable($source)) {
                throw new ConfigException('The file does not exist or cannot be read: ' . $source);
            }
            $size = (int) filesize($source);
            if ($size > $this->maxBytes) {
                throw $this->tooBig($size);
            }

            return new Upload($source, self::cleanName($filename ?? basename($source)));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'kelpgate_');
        if ($tmp === false) {
            throw new ConfigException('Could not create a temporary file in ' . sys_get_temp_dir());
        }
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            @unlink($tmp);
            throw new ConfigException('Could not write a temporary file in ' . sys_get_temp_dir());
        }
        try {
            if ($source instanceof BytesSource) {
                $size = strlen($source->bytes);
                if ($size > $this->maxBytes) {
                    throw $this->tooBig($size);
                }
                fwrite($out, $source->bytes);
            } elseif (is_resource($source)) {
                $size = 0;
                while (!feof($source)) {
                    $chunk = fread($source, 1048576);
                    if ($chunk === false) {
                        break;
                    }
                    $size += strlen($chunk);
                    if ($size > $this->maxBytes) {
                        throw $this->tooBig($size);
                    }
                    fwrite($out, $chunk);
                }
                /** @var array<string, mixed> $meta */
                $meta = stream_get_meta_data($source);
                $uri = $meta['uri'] ?? null;
                if ($filename === null && is_string($uri) && is_file($uri)) {
                    $filename = basename($uri);
                }
            } else {
                throw new ConfigException('source must be a file path, a stream resource, or bytes passed to scanBytes()');
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);

        return new Upload($tmp, self::cleanName($filename ?? 'upload.bin'), true);
    }

    private function tooBig(int $size): PayloadTooLargeException
    {
        return new PayloadTooLargeException(
            sprintf("The file is %d bytes, over this client's limit of %d bytes", $size, $this->maxBytes),
            ['status' => 413, 'code' => 'PAYLOAD_TOO_LARGE']
        );
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return $name === '' || $name === '.' || $name === '..' ? 'upload.bin' : $name;
    }

    private static function checkUrl(string $name, string $value): string
    {
        $parts = parse_url($value);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new ConfigException($name . ' is not a valid URL');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            throw new ConfigException($name . ' must use https (plain http is only allowed for localhost)');
        }

        return rtrim($value, '/');
    }

    // ---- results

    /**
     * A scan answers 200 (clean) or 406 (infected); both are verdicts. Everything else is an error.
     *
     * @param array{status: int, data: array<string, mixed>, requestId: string|null, response: Response} $r
     */
    private function scanResult(array $r): ScanResult
    {
        if (($r['status'] === 200 || $r['status'] === 406) && !empty($r['data']['av-status'])) {
            return new ScanResult($r['data'], $r['requestId']);
        }

        throw $this->fail($r);
    }

    /**
     * @param array{status: int, data: array<string, mixed>, requestId: string|null, response: Response} $r
     */
    private function accepted(array $r): AcceptedScan
    {
        if ($r['status'] === 202 && !empty($r['data']['scan_id'])) {
            return new AcceptedScan(
                (string) $r['data']['scan_id'],
                isset($r['data']['filename']) ? (string) $r['data']['filename'] : null,
                isset($r['data']['message']) ? (string) $r['data']['message'] : null,
                $r['requestId']
            );
        }

        throw $this->fail($r);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ConfigException('Could not encode the request as JSON');
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);

        return is_array($data) ? $data : ['error' => substr($body, 0, 200)];
    }
}
