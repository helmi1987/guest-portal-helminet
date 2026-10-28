<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Models\Grant;
use App\Models\NasClient;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\TermsVersion;
use App\Services\GrantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Core M2 paths: code redemption -> one-time credentials -> RADIUS accept, rebind, revoke, lockout.
 */
class RadiusFlowTest extends TestCase
{
    use RefreshDatabase;

    private const MAC_A = 'AA:BB:CC:00:00:01';

    private const MAC_B = 'AA:BB:CC:00:00:02';

    private Site $site;

    private NasClient $nas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com']]]);
        $this->nas = $this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        Process::fake(['*' => Process::result('Received Disconnect-ACK Id 1')]);
    }

    public function test_code_redeem_issues_credentials_accepted_by_radius_with_session_timeout(): void
    {
        $grant = $this->code(86400);

        $fields = $this->redeem($grant->code, self::MAC_A)->assertOk()
            ->assertSee('https://hotspot.example.com/login', false)->viewData('fields');

        $grant->refresh();
        $this->assertNotNull($grant->first_login_at);
        $this->assertEqualsWithDelta(86400, now()->diffInSeconds($grant->expires_at), 2);

        $reply = $this->authorize($fields['username'], $fields['password'], self::MAC_A)->assertOk()->json();
        $this->assertEqualsWithDelta(86400, $reply['reply:Session-Timeout'], 2);
        $this->assertSame(self::MAC_A, $grant->activeDevice()->mac);

        // One-time: the same credentials are rejected the second time.
        $this->authorize($fields['username'], $fields['password'], self::MAC_A)->assertStatus(401);

        $this->accounting('Start', 'sess-1', $fields['username'], self::MAC_A)->assertNoContent();
        $this->accounting('Stop', 'sess-1', $fields['username'], self::MAC_A, ['Acct-Input-Octets' => 1000])->assertNoContent();
        $session = SessionSummary::sole();
        $this->assertNotNull($session->stopped_at);
        $this->assertSame(1000, $session->accounting()->sole()->bytes_in);
    }

    public function test_credentials_are_only_handed_to_configured_hotspot_hosts(): void
    {
        $code = $this->code()->code;

        $this->redeem($code, self::MAC_A, 'https://attacker.example.net/login')->assertRedirect('/portal');
        $this->redeem($code, self::MAC_A, 'http://hotspot.example.com/login')->assertRedirect('/portal');
        $this->redeem($code, self::MAC_A)->assertOk()->assertViewHas('fields');
    }

    public function test_wrong_mac_or_password_is_rejected(): void
    {
        $fields = $this->redeem($this->code()->code, self::MAC_A)->viewData('fields');

        $this->authorize($fields['username'], 'wrong', self::MAC_A)->assertStatus(401);
        $this->authorize($fields['username'], $fields['password'], self::MAC_B)->assertStatus(401);
        $this->authorize($fields['username'], $fields['password'], self::MAC_A)->assertOk();
    }

    public function test_new_mac_rebinds_grant_and_disconnects_old_session(): void
    {
        $grant = $this->code();
        $first = $this->redeem($grant->code, self::MAC_A)->viewData('fields');
        $this->authorize($first['username'], $first['password'], self::MAC_A)->assertOk();
        $this->accounting('Start', 'sess-a', $first['username'], self::MAC_A);

        $second = $this->redeem($grant->code, self::MAC_B)->viewData('fields');
        $this->authorize($second['username'], $second['password'], self::MAC_B)->assertOk();

        $this->assertSame(self::MAC_B, $grant->activeDevice()->mac);
        $this->assertSame(2, $grant->devices()->count());
        Process::assertRan(fn (PendingProcess $p) => in_array('10.0.0.1:3799', $p->command, true)
            && str_contains($p->input, 'Acct-Session-Id = "sess-a"'));
    }

    public function test_revoked_or_expired_grant_is_rejected_and_sessions_disconnected(): void
    {
        $grant = $this->code();
        $fields = $this->redeem($grant->code, self::MAC_A)->viewData('fields');
        $this->authorize($fields['username'], $fields['password'], self::MAC_A)->assertOk();
        $this->accounting('Start', 'sess-1', $fields['username'], self::MAC_A);
        $pending = $this->redeem($grant->code, self::MAC_A)->viewData('fields');

        app(GrantManager::class)->revoke($grant);

        Process::assertRanTimes(fn (PendingProcess $p) => str_contains($p->input, 'sess-1'), 1);
        $this->authorize($pending['username'], $pending['password'], self::MAC_A)->assertStatus(401);
        $this->redeem($grant->code, self::MAC_A)->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.revoked.title'));

        $expired = $this->code();
        $this->redeem($expired->code, self::MAC_B)->assertOk();
        $this->travel(2)->days();
        $this->redeem($expired->code, self::MAC_B)->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.expired.title'));
    }

    public function test_router_redirects_to_signed_status_page_with_remaining_time(): void
    {
        $grant = $this->code(7200);
        $fields = $this->redeem($grant->code, self::MAC_A)->viewData('fields');
        $status = parse_url($fields['dst'], PHP_URL_PATH).'?'.parse_url($fields['dst'], PHP_URL_QUERY);

        // Router has not confirmed the login yet: waiting page refreshes itself.
        $this->onZone('guest')->get($status)->assertOk()->assertSee('http-equiv="refresh"', false);
        $this->onZone('guest')->get(parse_url($fields['dst'], PHP_URL_PATH))->assertForbidden();

        $this->authorize($fields['username'], $fields['password'], self::MAC_A)->assertOk();
        $this->onZone('guest')->withHeader('Accept-Language', 'de')->get($status)->assertOk()
            ->assertSee(__('portal.guest.online', [], 'de'))->assertSee('Stunde');

        app(GrantManager::class)->revoke($grant);
        $this->onZone('guest')->get($status)->assertForbidden()->assertSee(__('portal.guest.error_pages.revoked.title'));
    }

    public function test_scheduler_disconnects_sessions_of_expired_grants(): void
    {
        $grant = $this->code(3600);
        $fields = $this->redeem($grant->code, self::MAC_A)->viewData('fields');
        $this->authorize($fields['username'], $fields['password'], self::MAC_A)->assertOk();
        $this->accounting('Start', 'sess-1', $fields['username'], self::MAC_A);

        $this->artisan('portal:enforce-grants')->assertSuccessful();
        Process::assertNothingRan();

        $this->travel(61)->minutes();
        $this->accounting('Interim-Update', 'sess-1', $fields['username'], self::MAC_A);
        $this->artisan('portal:enforce-grants')->assertSuccessful();
        Process::assertRanTimes(fn (PendingProcess $p) => str_contains($p->input, 'sess-1'), 1);

        $this->travel(400)->days();
        $this->artisan('portal:purge')->assertSuccessful();
        $this->assertSame(0, Grant::withTrashed()->count());
    }

    public function test_lockout_after_repeated_failures(): void
    {
        $grant = $this->code();
        for ($i = 0; $i < 5; $i++) {
            $this->redeem('000001', self::MAC_A)->assertSessionHasErrors('code');
        }

        $this->redeem($grant->code, self::MAC_A)->assertStatus(429)
            ->assertSee(__('portal.guest.error_pages.locked.title'));
        $this->redeem($grant->code, self::MAC_B)->assertOk();
    }

    private function code(int $duration = 86400): Grant
    {
        return app(GrantManager::class)->createCode(['duration_s' => $duration, 'expiry_mode' => ExpiryMode::FirstLogin]);
    }

    private function redeem(string $code, string $mac, string $loginUrl = 'https://hotspot.example.com/login'): TestResponse
    {
        $this->onZone('guest')->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => $mac, 'ip' => '10.5.50.10',
            'link-login-only' => $loginUrl, 'link-orig' => 'http://example.com/',
        ]));

        return $this->onZone('guest')->post('/portal/code', [
            'first_name' => 'Test', 'last_name' => 'Guest', 'terms' => '1', 'code' => $code,
            'terms_version' => TermsVersion::current(app()->getLocale())?->id,
        ]);
    }

    private function authorize(string $user, string $password, string $mac): TestResponse
    {
        return $this->radius('authorize', [
            'User-Name' => $user, 'User-Password' => $password, 'Calling-Station-Id' => $mac,
        ]);
    }

    private function accounting(string $status, string $sessionId, string $user, string $mac, array $extra = []): TestResponse
    {
        return $this->radius('accounting', [
            'Acct-Status-Type' => $status, 'Acct-Session-Id' => $sessionId, 'User-Name' => $user,
            'Calling-Station-Id' => $mac, 'Framed-IP-Address' => '10.5.50.10', ...$extra,
        ]);
    }

    /** Request in rlm_rest JSON format. */
    private function radius(string $endpoint, array $attributes): TestResponse
    {
        $attributes['FreeRADIUS-Client-Shortname'] = $this->nas->shortname();
        $body = array_map(fn ($v) => ['type' => 'string', 'value' => [(string) $v]], $attributes);

        return $this->onZone('internal')
            ->withHeader('Authorization', 'Basic '.base64_encode('radius:test-internal-token'))
            ->postJson('/internal/radius/'.$endpoint, $body);
    }
}
