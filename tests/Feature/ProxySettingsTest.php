<?php

namespace Tests\Feature;

use App\Models\NasClient;
use App\Models\Site;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Proxy-related environment (CONCEPT.md §12): the router source-IP check stays on with TRUSTED_PROXIES,
 * and a trust-all TRUSTED_PROXIES is ignored so X-Forwarded-For cannot choose the client IP.
 */
class ProxySettingsTest extends TestCase
{
    use RefreshDatabase;

    /** Environment the application boots with, per test. */
    private const ENV = [
        'test_router_ip_check_stays_on_with_trusted_proxies' => ['TRUSTED_PROXIES' => '192.0.2.10'],
        'test_only_router_ip_check_false_switches_the_check_off_with_a_warning_at_start' => ['PORTAL_ROUTER_IP_CHECK' => 'false'],
        'test_trusted_proxies_star_does_not_let_x_forwarded_for_choose_the_ip' => ['TRUSTED_PROXIES' => '*'],
    ];

    protected function setUp(): void
    {
        foreach (self::ENV[$this->name()] ?? [] as $name => $value) {
            putenv("$name=$value");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        TrustProxies::flushState();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV[$this->name()] ?? []) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function router(): NasClient
    {
        $site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);

        return NasClient::find($site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799])->id);
    }

    private function download(NasClient $nas, string $remote, array $headers = [])
    {
        return $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => $remote])
            ->withHeaders(['X-Portal-Token' => $nas->cert_token] + $headers)->get("/router-cert/{$nas->id}/fingerprint");
    }

    public function test_router_ip_check_stays_on_with_trusted_proxies(): void
    {
        $this->assertTrue(config('portal.router_cert.check_source_ip'));
        $nas = $this->router();
        $this->download($nas, '192.0.2.10')->assertForbidden();
        $this->assertNotSame(403, $this->download($nas, '10.0.0.1')->status());
        // Through the trusted proxy the forwarded router address counts.
        $this->assertNotSame(403, $this->download($nas, '192.0.2.10', ['X-Forwarded-For' => '10.0.0.1'])->status());
    }

    public function test_only_router_ip_check_false_switches_the_check_off_with_a_warning_at_start(): void
    {
        $this->assertFalse(config('portal.router_cert.check_source_ip'));
        $this->assertNotSame(403, $this->download($this->router(), '198.51.100.7')->status());

        Log::spy();
        $this->assertSame(0, Artisan::call('portal:init'));
        $this->assertStringContainsString('PORTAL_ROUTER_IP_CHECK=false', Artisan::output());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Router source IP check is off'))->once();
    }

    public function test_router_ip_check_is_on_by_default_without_warning(): void
    {
        $this->assertTrue(config('portal.router_cert.check_source_ip'));
        Log::spy();
        Artisan::call('portal:init');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_trust_all_values_are_refused(): void
    {
        foreach (['*', '**', '0.0.0.0/0', '::/0', '192.0.2.10, 0.0.0.0/0', ' * '] as $value) {
            $this->assertTrue(TrustedProxies::trustsAll($value), $value);
            $this->assertNull(TrustedProxies::resolve($value), $value);
        }
        $this->assertSame('192.0.2.10,10.0.0.0/8', TrustedProxies::resolve('192.0.2.10,10.0.0.0/8'));
        $this->assertNull(TrustedProxies::resolve(''));
        $this->assertNull(TrustedProxies::resolve(null));
    }

    public function test_trusted_proxies_star_does_not_let_x_forwarded_for_choose_the_ip(): void
    {
        $nas = $this->router();
        // A guest claiming the router's address by X-Forwarded-For is still seen with its own address.
        $this->download($nas, '10.20.0.55', ['X-Forwarded-For' => '10.0.0.1'])->assertForbidden();

        Log::spy();
        Artisan::call('portal:init');
        $this->assertStringContainsString('TRUSTED_PROXIES trusts every client', Artisan::output());
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'TRUSTED_PROXIES'))->once();
    }
}
