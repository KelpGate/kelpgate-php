<?php

declare(strict_types=1);

namespace Kelpgate\Tests;

use Kelpgate\Exception\NetworkException;
use Kelpgate\Exception\TimeoutException;
use Kelpgate\Http\CurlTransport;
use Kelpgate\Http\Request;
use Kelpgate\Http\Upload;
use PHPUnit\Framework\TestCase;

/** Runs the cURL transport against PHP's built-in web server. */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    /** @var string */
    private static $base = '';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl') || !function_exists('proc_open')) {
            return;
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            return;
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/Support/router.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return;
        }
        self::$server = $process;
        self::$base = 'http://127.0.0.1:' . $port;
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    protected function setUp(): void
    {
        if (self::$base === '') {
            $this->markTestSkipped('ext-curl or a local web server is not available');
        }
    }

    public function testSendsHeadersAndABody(): void
    {
        $r = (new CurlTransport())->send(new Request(
            'POST',
            self::$base . '/echo',
            ['authorization' => 'Bearer abc', 'user-agent' => 'kelpgate-test', 'content-type' => 'application/json'],
            '{"url":"x"}'
        ));
        $data = json_decode($r->body, true);
        $this->assertSame(200, $r->status);
        $this->assertSame('POST', $data['method']);
        $this->assertSame('Bearer abc', $data['authorization']);
        $this->assertSame('kelpgate-test', $data['user_agent']);
        $this->assertSame('{"url":"x"}', $data['body']);
    }

    public function testUploadsAFileAsMultipartWithExtraFields(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kg');
        file_put_contents($path, 'binary content');
        $r = (new CurlTransport())->send(new Request(
            'POST',
            self::$base . '/upload',
            [],
            null,
            new Upload($path, 'renamed.bin'),
            ['webhook_url' => 'https://example.com/hook']
        ));
        unlink($path);
        $data = json_decode($r->body, true);
        $this->assertSame('renamed.bin', $data['file']['name']);
        $this->assertSame('binary content', $data['file']['contents']);
        $this->assertSame(['webhook_url' => 'https://example.com/hook'], $data['fields']);
    }

    public function testErrorStatusesAndHeadersComeBackAsAResponse(): void
    {
        $r = (new CurlTransport())->send(new Request('GET', self::$base . '/teapot'));
        $this->assertSame(418, $r->status);
        $this->assertSame('req-teapot', $r->header('X-Request-Id'));
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $r = (new CurlTransport())->send(new Request('GET', self::$base . '/redirect'));
        $this->assertSame(302, $r->status);
    }

    public function testATimeoutIsATimeoutException(): void
    {
        $this->expectException(TimeoutException::class);
        (new CurlTransport())->send(new Request('GET', self::$base . '/slow', [], null, null, [], 0.3));
    }

    public function testARequestWithoutAUrlIsRejected(): void
    {
        $this->expectException(NetworkException::class);
        (new CurlTransport())->send(new Request('GET', ''));
    }

    public function testAnUnreachableHostIsANetworkException(): void
    {
        $this->expectException(NetworkException::class);
        (new CurlTransport())->send(new Request('GET', 'http://127.0.0.1:1/', [], null, null, [], 2.0));
    }
}
