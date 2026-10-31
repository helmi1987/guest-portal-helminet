<?php

namespace Tests\Feature\Federation;

use App\Federation\BrandingFiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Branding downloads from peers stop once the size limit is exceeded, also when the response has no
 * Content-Length (CURLOPT_MAXFILESIZE only checks an announced length). Real transfer through curl
 * against a local PHP server that streams the body in chunks.
 */
class BrandingDownloadLimitTest extends TestCase
{
    private ?Process $server = null;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('curl not available');
        }
        $this->dir = sys_get_temp_dir().'/portal-test-'.getmypid().'-stream-'.bin2hex(random_bytes(3));
        mkdir($this->dir);
        // 64 chunks of 16 KiB (1 MiB) without Content-Length, flushed one by one.
        file_put_contents($this->dir.'/router.php', '<?php header("Content-Type: application/octet-stream");'
            .' for ($i = 0; $i < 64; $i++) { echo str_repeat("x", 16384); flush(); usleep(20000); }');
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        @unlink($this->dir.'/router.php');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function serve(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->server = new Process([PHP_BINARY, '-S', "127.0.0.1:$port", $this->dir.'/router.php'], $this->dir);
        $this->server->start();
        for ($i = 0; $i < 100; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return "http://127.0.0.1:$port/file";
            }
            usleep(50000);
        }
        $this->fail('local test server did not start');
    }

    public function test_download_without_content_length_is_aborted_at_the_limit(): void
    {
        $url = $this->serve();

        // No proxy from the environment for the local server.
        $headers = Http::withOptions(['proxy' => ''])->get($url)->throw();
        $this->assertSame(1048576, strlen($headers->body()));
        $this->assertSame('', $headers->header('Content-Length'));

        $exceeded = null;
        $started = microtime(true);
        try {
            Http::withOptions(['proxy' => ''] + BrandingFiles::downloadLimit(50000, $exceeded))->get($url);
            $this->fail('download over the limit not aborted');
        } catch (ConnectionException|RequestException) {
        }
        $this->assertTrue($exceeded);
        // Aborted after a few chunks, long before the full body (64 × 20 ms).
        $this->assertLessThan(1.0, microtime(true) - $started);

        $exceeded = null;
        $response = Http::withOptions(['proxy' => ''] + BrandingFiles::downloadLimit(2000000, $exceeded))->get($url);
        $this->assertSame(1048576, strlen($response->body()));
        $this->assertFalse($exceeded);
    }
}
