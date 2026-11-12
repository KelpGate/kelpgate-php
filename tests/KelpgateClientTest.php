<?php

declare(strict_types=1);

namespace Kelpgate\Tests;

use Kelpgate\Auth\ArrayTokenCache;
use Kelpgate\Exception\AuthException;
use Kelpgate\Exception\ConfigException;
use Kelpgate\Exception\InfectedException;
use Kelpgate\Exception\NetworkException;
use Kelpgate\Exception\PayloadTooLargeException;
use Kelpgate\Exception\QuotaExceededException;
use Kelpgate\Exception\RateLimitedException;
use Kelpgate\Exception\ScanException;
use Kelpgate\Exception\ServerException;
use Kelpgate\Exception\TimeoutException;
use Kelpgate\Exception\UnsupportedFileException;
use Kelpgate\Http\Response;
use Kelpgate\KelpgateClient;
use Kelpgate\PendingScan;
use Kelpgate\ScanResult;
use Kelpgate\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class KelpgateClientTest extends TestCase
{
    private const CLEAN = [
        'av-status' => 'CLEAN',
        'av-signature' => 'OK',
        'sha256' => 'abc',
        'scan_id' => 's1',
        'rule_authors' => ['ESET'],
    ];
    private const INFECTED = ['av-status' => 'INFECTED', 'av-signature' => 'Eicar-Test-Signature'];

    /** @var FakeTransport */
    private $http;

    /** @var int[] */
    private $sleeps = [];

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->sleeps = [];
    }

    /** @param array<string, mixed> $extra */
    private function client(array $extra = []): KelpgateClient
    {
        return new KelpgateClient($extra + [
            'apiUrl' => 'https://api.example.com',
            'tokenUrl' => 'https://auth.example.com/oauth2/token',
            'clientId' => 'id',
            'clientSecret' => 's/e:cret',
            'transport' => $this->http,
            'sleeper' => function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        ]);
    }

    private function tempFile(string $contents = 'hello', string $name = 'upload.txt'): string
    {
        $dir = sys_get_temp_dir() . '/kelpgate_test_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    // ---- configuration

    public function testRequiresHttpsExceptForLoopback(): void
    {
        $this->expectException(ConfigException::class);
        new KelpgateClient(['apiUrl' => 'http://api.example.com', 'bearerToken' => 't']);
    }

    public function testAllowsPlainHttpOnLocalhost(): void
    {
        $client = new KelpgateClient(['apiUrl' => 'http://localhost:9000', 'bearerToken' => 't', 'transport' => $this->http]);
        $this->http->json(200, ['status' => 'OK', 'signature_version' => 'v1'], ['x-request-id' => 'r1']);
        $health = $client->health();
        $this->assertTrue($health->ok);
        $this->assertSame('v1', $health->signatureVersion);
        $this->assertSame('r1', $health->requestId);
    }

    public function testNeedsCredentialsOrToken(): void
    {
        $this->expectException(ConfigException::class);
        new KelpgateClient(['apiUrl' => 'https://api.example.com', 'tokenUrl' => 'https://auth.example.com/t', 'transport' => $this->http]);
    }

    public function testCredentialsStayOutOfDumps(): void
    {
        $client = $this->client(['clientSecret' => 'super-secret-value']);
        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString('super-secret-value', $dump);
        $this->assertStringContainsString('api.example.com', $dump);
    }

    public function testFromEnvUsesProductionDefaultsAndOverrides(): void
    {
        $client = KelpgateClient::fromEnv(
            ['KELPGATE_CLIENT_ID' => 'a', 'KELPGATE_CLIENT_SECRET' => 'b'],
            ['transport' => $this->http]
        );
        $this->http->token()->json(200, self::CLEAN);
        $client->scanBytes('x');
        $this->assertSame('https://auth.kelpgate.cloud/oauth2/token', $this->http->requests[0]->url);
        $this->assertSame('https://api.kelpgate.cloud/api/v1/scan/file', $this->http->requests[1]->url);
    }

    public function testFromEnvAcceptsAStaticToken(): void
    {
        $client = KelpgateClient::fromEnv(['KELPGATE_TOKEN' => 'static'], ['transport' => $this->http]);
        $this->http->json(200, self::CLEAN);
        $client->scanBytes('x');
        $this->assertSame('Bearer static', $this->http->requests[0]->headers['authorization']);
    }

    // ---- authentication

    public function testTokenRequestUsesBasicAuthWithEncodedCredentials(): void
    {
        $this->http->token()->json(200, self::CLEAN);
        $this->client()->scanBytes('x');
        $token = $this->http->requests[0];
        $this->assertSame('Basic ' . base64_encode('id:s%2Fe%3Acret'), $token->headers['authorization']);
        $this->assertStringContainsString('grant_type=client_credentials', (string) $token->body);
        $this->assertStringContainsString('scope=scan', (string) $token->body);
        $this->assertSame('Bearer tok-1', $this->http->requests[1]->headers['authorization']);
    }

    public function testTokenIsCachedAndReused(): void
    {
        $this->http->token()->json(200, self::CLEAN)->json(200, self::CLEAN);
        $client = $this->client();
        $client->scanBytes('1');
        $client->scanBytes('2');
        $this->assertCount(3, $this->http->requests); // one token request, two scans
    }

    public function testTokenIsRenewedBeforeItExpires(): void
    {
        $cache = new ArrayTokenCache();
        $client = $this->client(['tokenCache' => $cache]);
        $this->http->token('old', 60)->json(200, self::CLEAN)->token('new', 3600)->json(200, self::CLEAN);
        $client->scanBytes('1');
        $client->scanBytes('2'); // 60 s is inside the 120 s refresh-ahead window
        $this->assertSame('Bearer old', $this->http->requests[1]->headers['authorization']);
        $this->assertSame('Bearer new', $this->http->requests[3]->headers['authorization']);
    }

    public function testAnEarlyRenewalFailureKeepsUsingTheStillValidToken(): void
    {
        $client = $this->client();
        $this->http->token('old', 100)->json(200, self::CLEAN)->json(500, ['error' => 'down'])->json(200, self::CLEAN);
        $client->scanBytes('1');
        $result = $client->scanBytes('2');
        $this->assertFalse($result->infected);
        $this->assertSame('Bearer old', $this->http->requests[3]->headers['authorization']);
    }

    public function testA401TriggersOneFreshTokenAndOneRetry(): void
    {
        $this->http->token('t1')->json(401, ['error' => 'expired'])->token('t2')->json(200, self::CLEAN);
        $result = $this->client()->scanBytes('x');
        $this->assertFalse($result->infected);
        $this->assertSame('Bearer t2', $this->http->requests[3]->headers['authorization']);
    }

    public function testASecond401IsAnAuthError(): void
    {
        $this->http->token()->json(401, ['error' => 'no'])->token()->json(401, ['error' => 'no']);
        $this->expectException(AuthException::class);
        $this->client()->scanBytes('x');
    }

    public function testTokenFailureIsAnAuthError(): void
    {
        $this->http->json(401, ['error' => 'invalid_client', 'error_description' => 'bad secret']);
        try {
            $this->client()->scanBytes('x');
            $this->fail('expected an AuthException');
        } catch (AuthException $e) {
            $this->assertSame('TOKEN_REQUEST_FAILED', $e->getErrorCode());
            $this->assertStringContainsString('bad secret', $e->getMessage());
        }
    }

    // ---- scans

    public function testScanFileSendsTheFileAndReturnsAVerdict(): void
    {
        $this->http->token()->json(200, self::CLEAN, ['x-request-id' => 'req-9']);
        $path = $this->tempFile('file bytes', 'report.pdf');
        $result = $this->client()->scanFile($path);
        $this->assertInstanceOf(ScanResult::class, $result);
        $this->assertFalse($result->infected);
        $this->assertTrue($result->isClean());
        $this->assertSame('CLEAN', $result->status);
        $this->assertSame('abc', $result->sha256);
        $this->assertSame(['ESET'], $result->ruleAuthors);
        $this->assertSame('req-9', $result->requestId);
        $upload = $this->http->requests[1]->upload;
        $this->assertNotNull($upload);
        $this->assertSame('report.pdf', $upload->filename);
        $this->assertSame(['file bytes'], $this->http->uploaded);
        $this->assertSame('https://api.example.com/api/v1/scan/file', $this->http->requests[1]->url);
    }

    public function testAnInfectedFileIsAVerdictNotAnError(): void
    {
        $this->http->token()->json(406, self::INFECTED);
        $result = $this->client()->scanBytes('X5O!P%@AP', ['filename' => 'eicar.com']);
        $this->assertTrue($result->infected);
        $this->assertSame('Eicar-Test-Signature', $result->signature);
        $this->expectException(InfectedException::class);
        $result->assertClean();
    }

    public function testInfectedExceptionCarriesTheResult(): void
    {
        $result = new ScanResult(self::INFECTED, 'r1');
        try {
            $result->assertClean();
            $this->fail('expected an InfectedException');
        } catch (InfectedException $e) {
            $this->assertSame($result, $e->getResult());
            $this->assertSame('Eicar-Test-Signature', $e->getSignature());
            $this->assertSame('INFECTED', $e->getErrorCode());
            $this->assertSame(406, $e->getStatus());
        }
    }

    public function testPolicyAndDegradedFlags(): void
    {
        $policy = new ScanResult(['av-status' => 'INFECTED', 'av-signature' => 'POLICY:ENCRYPTED_ARCHIVE_REJECTED']);
        $this->assertTrue($policy->policyRejected);
        $degraded = new ScanResult(['av-status' => 'CLEAN', 'av-signature' => 'OK (scan coverage reduced)']);
        $this->assertTrue($degraded->degraded);
        $this->assertFalse((new ScanResult(self::CLEAN))->degraded);
    }

    public function testScanBytesUsesTheGivenFilenameAndCleansItUp(): void
    {
        $this->http->token()->json(200, self::CLEAN);
        $this->client()->scanBytes('abc', ['filename' => '../../etc/passwd']);
        $upload = $this->http->requests[1]->upload;
        $this->assertNotNull($upload);
        $this->assertSame('passwd', $upload->filename);
        $this->assertFileDoesNotExist($upload->path); // the temp copy is removed
        $this->assertSame(['abc'], $this->http->uploaded);
    }

    public function testScanFileAcceptsAStream(): void
    {
        $this->http->token()->json(200, self::CLEAN);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'streamed');
        rewind($stream);
        $this->client()->scanFile($stream, ['filename' => 's.bin']);
        $this->assertSame(['streamed'], $this->http->uploaded);
    }

    public function testMissingFileIsAConfigError(): void
    {
        $this->expectException(ConfigException::class);
        $this->client()->scanFile('/no/such/file.txt');
    }

    public function testMaxBytesStopsBigFilesBeforeAnyRequest(): void
    {
        $this->expectException(PayloadTooLargeException::class);
        try {
            $this->client(['maxBytes' => 3])->scanBytes('toolong');
        } finally {
            $this->assertSame([], $this->http->requests);
        }
    }

    public function testScanUrlSendsJson(): void
    {
        $this->http->token()->json(200, self::CLEAN);
        $this->client()->scanUrl('https://example.com/f.zip');
        $request = $this->http->requests[1];
        $this->assertSame('/api/v1/scan/url', parse_url($request->url, PHP_URL_PATH));
        $this->assertSame('application/json', $request->headers['content-type']);
        $this->assertSame('{"url":"https://example.com/f.zip"}', $request->body);
    }

    // ---- async

    public function testAsyncFileScanSendsTheWebhook(): void
    {
        $this->http->token()->json(202, ['scan_id' => 'sc-1', 'filename' => 'a.txt', 'message' => 'queued']);
        $accepted = $this->client()->scanFileAsync($this->tempFile(), ['webhookUrl' => 'https://example.com/hook']);
        $this->assertSame('sc-1', $accepted->scanId);
        $this->assertSame(['webhook_url' => 'https://example.com/hook'], $this->http->requests[1]->fields);
    }

    public function testAsyncUrlScan(): void
    {
        $this->http->token()->json(202, ['scan_id' => 'sc-2']);
        $accepted = $this->client()->scanUrlAsync('https://example.com/f', ['webhookUrl' => 'https://example.com/hook']);
        $this->assertSame('sc-2', $accepted->scanId);
        $body = json_decode((string) $this->http->requests[1]->body, true);
        $this->assertSame(['url' => 'https://example.com/f', 'webhook_url' => 'https://example.com/hook'], $body);
    }

    public function testGetAsyncResultReturnsPendingThenAResult(): void
    {
        $this->http->token()->json(202, ['av-status' => 'PROCESSING'])->json(200, self::CLEAN);
        $client = $this->client();
        $first = $client->getAsyncResult('sc-1');
        $this->assertInstanceOf(PendingScan::class, $first);
        $this->assertSame('sc-1', $first->scanId);
        $second = $client->getAsyncResult('sc-1');
        $this->assertInstanceOf(ScanResult::class, $second);
        $this->assertStringContainsString('scan_id=sc-1', $this->http->requests[1]->url);
    }

    public function testGetAsyncResultUsesTheUrlEndpointForUrlScans(): void
    {
        $this->http->token()->json(200, self::CLEAN);
        $this->client()->getAsyncResult('sc 1', ['kind' => 'url']);
        $this->assertStringContainsString('/api/v1/async-scan/url?scan_id=sc%201', $this->http->requests[1]->url);
    }

    public function testWaitForResultPollsUntilDone(): void
    {
        $this->http->token()->json(202, [])->json(202, [])->json(200, self::CLEAN);
        $result = $this->client()->waitForResult('sc-1', ['intervalMs' => 5, 'timeout' => 5]);
        $this->assertFalse($result->infected);
        $this->assertSame([5, 5], $this->sleeps);
    }

    public function testWaitForResultTimesOut(): void
    {
        $this->http->token();
        for ($i = 0; $i < 10; $i++) {
            $this->http->json(202, []);
        }
        $this->expectException(TimeoutException::class);
        $realSleep = static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->client(['sleeper' => $realSleep])->waitForResult('sc-1', ['intervalMs' => 30, 'timeout' => 0.07]);
    }

    // ---- errors and retries

    /** @return array<string, array{int, class-string<\Kelpgate\Exception\KelpgateException>, string}> */
    public function statusErrors(): array
    {
        return [
            '402' => [402, QuotaExceededException::class, 'QUOTA_EXCEEDED'],
            '403' => [403, AuthException::class, 'FORBIDDEN'],
            '413' => [413, PayloadTooLargeException::class, 'PAYLOAD_TOO_LARGE'],
            '415' => [415, UnsupportedFileException::class, 'UNSUPPORTED_FILE'],
            '400' => [400, ScanException::class, 'SCAN_ERROR'],
            '412' => [412, ScanException::class, 'SCAN_ERROR'],
            '500' => [500, ServerException::class, 'SERVER_ERROR'],
        ];
    }

    /**
     * @dataProvider statusErrors
     * @param class-string<\Kelpgate\Exception\KelpgateException> $class
     */
    public function testStatusCodesMapToTypedExceptions(int $status, string $class, string $code): void
    {
        $this->http->token()->json($status, ['error' => 'nope'], ['x-request-id' => 'rid']);
        try {
            $this->client(['maxRetries' => 0])->scanBytes('x');
            $this->fail('expected ' . $class);
        } catch (\Kelpgate\Exception\KelpgateException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame($code, $e->getErrorCode());
            $this->assertSame($status, $e->getStatus());
            $this->assertSame('rid', $e->getRequestId());
            $this->assertSame('nope', $e->getMessage());
        }
    }

    public function testQuotaDetailsAreExposed(): void
    {
        $this->http->token()->json(402, [
            'error' => 'quota',
            'quota' => 100,
            'usage' => 100,
            'upgrade_url' => 'https://kelpgate.cloud/plans',
        ]);
        try {
            $this->client()->scanBytes('x');
            $this->fail('expected a QuotaExceededException');
        } catch (QuotaExceededException $e) {
            $this->assertSame(100, $e->getQuota());
            $this->assertSame(100, $e->getUsage());
            $this->assertSame('https://kelpgate.cloud/plans', $e->getUpgradeUrl());
        }
    }

    public function testRateLimitIsRetriedHonoringRetryAfter(): void
    {
        $this->http->token()->json(429, ['error' => 'slow'], ['retry-after' => '2'])->json(200, self::CLEAN);
        $this->client()->scanBytes('x');
        $this->assertSame([2000], $this->sleeps);
    }

    public function testRateLimitExceptionAfterTheRetriesRunOut(): void
    {
        $this->http->token()->json(429, ['error' => 'slow'], ['retry-after' => '7']);
        try {
            $this->client(['maxRetries' => 0])->scanBytes('x');
            $this->fail('expected a RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(7, $e->getRetryAfterSeconds());
        }
    }

    public function testTransientStatusesAreRetriedWithBackoff(): void
    {
        $this->http->token()->json(503, [])->json(502, [])->json(200, self::CLEAN);
        $this->client(['retryDelayMs' => 100])->scanBytes('x');
        $this->assertCount(2, $this->sleeps);
        $this->assertGreaterThanOrEqual(100, $this->sleeps[0]);
        $this->assertGreaterThanOrEqual(200, $this->sleeps[1]);
    }

    public function testNetworkErrorsAreRetriedThenThrown(): void
    {
        $this->http->token()
            ->push(new NetworkException('reset'))
            ->push(new NetworkException('reset'))
            ->push(new NetworkException('reset'));
        $this->expectException(NetworkException::class);
        $this->client(['maxRetries' => 2])->scanBytes('x');
    }

    public function testTimeoutsAreNetworkErrors(): void
    {
        $this->http->token()->push(new TimeoutException('too slow'));
        try {
            $this->client(['maxRetries' => 0])->scanBytes('x');
            $this->fail('expected a TimeoutException');
        } catch (NetworkException $e) {
            $this->assertInstanceOf(TimeoutException::class, $e);
            $this->assertSame('TIMEOUT', $e->getErrorCode());
        }
    }

    public function testRedirectsAreNeverFollowedByTheClientHeaders(): void
    {
        $this->http->token()->json(200, self::CLEAN);
        $this->client()->scanBytes('x');
        $this->assertStringStartsWith('kelpgate-php/', $this->http->requests[1]->headers['user-agent']);
    }

    public function testHealthNeedsNoCredentials(): void
    {
        $this->http->json(200, ['status' => 'OK', 'signature_version' => 'abc']);
        $health = $this->client()->health();
        $this->assertTrue($health->ok);
        $this->assertCount(1, $this->http->requests); // no token request
        $this->assertArrayNotHasKey('authorization', $this->http->requests[0]->headers);
    }

    public function testHealthReportsAnUnavailableService(): void
    {
        $this->http->json(503, ['status' => 'UPDATING']);
        $health = $this->client(['maxRetries' => 0])->health();
        $this->assertFalse($health->ok);
        $this->assertSame(503, $health->status);
    }

    public function testNonJsonErrorBodiesAreStillReported(): void
    {
        $this->http->token()->push(new Response(502, '<html>Bad gateway</html>'));
        try {
            $this->client(['maxRetries' => 0])->scanBytes('x');
            $this->fail('expected a ServerException');
        } catch (ServerException $e) {
            $this->assertStringContainsString('Bad gateway', $e->getMessage());
        }
    }
}
