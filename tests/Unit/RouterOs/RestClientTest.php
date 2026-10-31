<?php

namespace Tests\Unit\RouterOs;

use App\Federation\PeerCertificate;
use App\Models\NasClient;
use App\Nas\RouterOs\RestClient;
use App\Nas\RouterOs\RouterOsClients;
use App\Nas\RouterOs\RouterOsException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** RouterOS REST client (print only) and the TLS pin of the router access (CONCEPT.md §9). */
class RestClientTest extends TestCase
{
    private const PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private function client(): RestClient
    {
        return new RestClient('10.0.0.1', 443, 'portal-ro', 'test-pass', self::PIN, 1, 1);
    }

    private function kind(callable $fn): ?string
    {
        try {
            $fn();
        } catch (RouterOsException $e) {
            return $e->kind;
        }

        return null;
    }

    public function test_print_posts_proplist_and_query_with_basic_auth(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://10.0.0.1/rest/ip/firewall/address-list/print' => Http::response([
            ['.id' => '*1', 'list' => 'PORTAL', 'address' => '10.0.0.10', 'dynamic' => false],
        ]), 'https://10.0.0.1/rest/system/resource/print' => Http::response(['version' => '7.24.5'])]);

        $rows = $this->client()->print('/ip firewall address-list', ['.id', 'list', 'address', 'dynamic'], ['list=PORTAL', 'list=RFC1918']);
        $this->assertSame([['.id' => '*1', 'list' => 'PORTAL', 'address' => '10.0.0.10', 'dynamic' => 'false']], $rows);
        $this->assertSame([['version' => '7.24.5']], $this->client()->print('/system resource', ['version']));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://10.0.0.1/rest/ip/firewall/address-list/print'
            && $r->data() === ['.proplist' => ['.id', 'list', 'address', 'dynamic'], '.query' => ['list=PORTAL', 'list=RFC1918', '#|']]
            && $r->header('Authorization')[0] === 'Basic '.base64_encode('portal-ro:test-pass'));
        Http::assertSentCount(2);
    }

    public function test_error_kinds(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/rest/interface/print' => Http::response(['error' => 401, 'message' => 'Unauthorized'], 401),
            '*/rest/user/print' => Http::response(['error' => 403], 403),
            '*/rest/caps-man/manager/print' => Http::response(['error' => 400, 'message' => 'Bad Request', 'detail' => 'no such command prefix'], 400),
            '*/rest/ip/pool/print' => Http::response('oops', 500),
            '*/rest/ip/address/print' => Http::failedConnection('cURL error 28: Operation timed out after 1001 milliseconds'),
            '*/rest/ip/hotspot/print' => Http::failedConnection('cURL error 90: SSL: public key does not match pinned public key'),
            '*/rest/radius/print' => Http::failedConnection('cURL error 7: Failed to connect to 10.0.0.1 port 443'),
        ]);
        $this->assertSame('auth', $this->kind(fn () => $this->client()->print('/interface', ['name'])));
        $this->assertSame('denied', $this->kind(fn () => $this->client()->print('/user', ['name'])));
        $this->assertSame('menu', $this->kind(fn () => $this->client()->print('/caps-man manager', ['enabled'])));
        $this->assertSame('protocol', $this->kind(fn () => $this->client()->print('/ip pool', ['name'])));
        $this->assertSame('timeout', $this->kind(fn () => $this->client()->print('/ip address', ['address'])));
        $this->assertSame('tls', $this->kind(fn () => $this->client()->print('/ip hotspot', ['name'])));
        $this->assertSame('connect', $this->kind(fn () => $this->client()->print('/radius', ['address'])));
        // Refused calls never reach the router
        $this->assertSame('config', $this->kind(fn () => $this->client()->print('/system reboot', ['name'])));
        $this->assertSame('config', $this->kind(fn () => $this->client()->print('/radius', ['address', 'secret'])));
        Http::assertSentCount(7);
    }

    public function test_400_404_are_menu_missing_only_with_a_no_such_command_detail(): void
    {
        Http::fake([
            '*/rest/ip/pool/print' => Http::response(['error' => 400, 'detail' => 'no such command prefix'], 400),
            '*/rest/ip/address/print' => Http::response(['error' => 404, 'detail' => 'no such command or directory (print)'], 404),
            '*/rest/radius/print' => Http::response(['error' => 400, 'detail' => 'invalid value for argument proplist'], 400),
            '*/rest/user/print' => Http::response('<html>nginx</html>', 404),
            '*/rest/interface/print' => Http::response(str_repeat('x', 100), 200),
        ]);
        $this->assertSame('menu', $this->kind(fn () => $this->client()->print('/ip pool', ['name'])));
        $this->assertSame('menu', $this->kind(fn () => $this->client()->print('/ip address', ['address'])));
        $this->assertSame('protocol', $this->kind(fn () => $this->client()->print('/radius', ['address'])));
        $this->assertSame('protocol', $this->kind(fn () => $this->client()->print('/user', ['name'])));
        // Response above the size cap
        $client = new RestClient('10.0.0.1', 443, 'portal-ro', 'test-pass', self::PIN, 1, 1, 50);
        $this->assertSame('protocol', $this->kind(fn () => $client->print('/interface', ['name'])));
    }

    public function test_pin_is_the_configured_one_or_the_managed_router_certificate(): void
    {
        $dir = sys_get_temp_dir().'/portal-test-'.getmypid().'-pin';
        @mkdir($dir, 0700, true);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'hotspot.guest.example.com'], $key), null, $key, 30);
        openssl_x509_export($cert, $pem);
        file_put_contents($dir.'/router.crt', $pem);
        config(['portal.router_cert.cert' => $dir.'/router.crt']);

        $nas = new NasClient(['api_host' => '10.0.0.1', 'api_type' => 'rest']);
        $this->assertSame(PeerCertificate::pin($pem), RouterOsClients::pin($nas));
        $nas->api_pin = self::PIN;
        $this->assertSame(self::PIN, RouterOsClients::pin($nas));

        config(['portal.router_cert.cert' => $dir.'/missing.crt']);
        $nas->api_pin = null;
        $this->assertSame('tls', $this->kind(fn () => RouterOsClients::pin($nas)));
        @unlink($dir.'/router.crt');
    }
}
