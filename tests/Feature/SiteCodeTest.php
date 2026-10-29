<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\SiteCode;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\GrantManager;
use App\Services\SiteCodeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Site codes (CONCEPT.md §8): one shared code per site, one grant per device, history, notice.
 */
class SiteCodeTest extends TestCase
{
    use RefreshDatabase;

    private const MAC_A = 'AA:BB:CC:00:00:01';

    private const MAC_B = 'AA:BB:CC:00:00:02';

    private const MAC_C = 'AA:BB:CC:00:00:03';

    private Site $site;

    private Site $other;

    private NasClient $nas;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com',
                'setup' => ['ssid' => 'Guest WiFi']]]);
        $this->other = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot-b.example.com']]]);
        $this->nas = $this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        $this->manager = User::factory()->create();
        Process::fake(['*' => Process::result('Received Disconnect-ACK Id 1')]);
    }

    public function test_manager_generates_regenerates_and_sees_history_all_audited(): void
    {
        $this->admin()->post("/admin/sites/{$this->site->id}/site-code", ['duration' => '1d'])
            ->assertRedirect("/admin/sites/{$this->site->id}/site-code");
        $first = SiteCode::sole();
        $this->assertTrue($first->isActive());
        $this->assertSame(86400, $first->duration_s);
        $this->assertNull($first->max_devices);
        $this->assertSame($this->manager->id, $first->created_by);

        $this->admin()->post("/admin/sites/{$this->site->id}/site-code", ['duration' => 'custom', 'custom_hours' => '1.5', 'max_devices' => 5]);
        $first->refresh();
        $second = SiteCode::query()->where('id', '!=', $first->id)->sole();
        $this->assertFalse($first->isActive());
        $this->assertNotNull($first->deactivated_at);
        $this->assertSame($this->manager->id, $first->deactivated_by);
        $this->assertTrue($second->isActive());
        $this->assertSame(5400, $second->duration_s);
        $this->assertSame($second->id, SiteCodeManager::current($this->site)->id);

        $this->admin()->get("/admin/sites/{$this->site->id}/site-code")->assertOk()
            ->assertSee($first->code)->assertSee($second->code)->assertSee($this->manager->name)
            ->assertSee(__('portal.site_codes.history'));
        $this->admin()->get('/admin/site-codes')->assertOk()->assertSee($second->code)->assertSee('Site B');

        $this->admin()->put("/admin/sites/{$this->site->id}/site-code", ['duration' => '8h', 'valid_until' => now()->addWeek()->format('Y-m-d\TH:i')]);
        $second->refresh();
        $this->assertSame(28800, $second->duration_s);
        $this->assertNull($second->max_devices);
        $this->assertNotNull($second->valid_until);
        $this->admin()->put("/admin/sites/{$this->site->id}/site-code", ['duration' => '8h', 'valid_until' => '2000-01-01T00:00'])
            ->assertSessionHasErrors('valid_until');

        $this->admin()->post("/admin/sites/{$this->site->id}/site-code/deactivate")->assertRedirect();
        $this->assertNull(SiteCodeManager::current($this->site));
        $this->assertSame(2, SiteCode::query()->count());

        $this->assertSame(['site_code.created', 'site_code.created', 'site_code.updated', 'site_code.deactivated'],
            AuditLog::query()->orderBy('id')->pluck('action')->all());
        // The code itself never goes to the audit log.
        foreach (AuditLog::all() as $entry) {
            $this->assertStringNotContainsString($second->code, json_encode($entry->data));
        }
    }

    public function test_two_devices_log_in_with_the_site_code_each_with_its_own_grant_and_session_timeout(): void
    {
        $code = $this->siteCode(28800)->code;

        $a = $this->redeem($code, self::MAC_A)->assertOk()->viewData('fields');
        $b = $this->redeem($code, self::MAC_B)->assertOk()->viewData('fields');

        $replyA = $this->authorize($a['username'], $a['password'], self::MAC_A)->assertOk()->json();
        $replyB = $this->authorize($b['username'], $b['password'], self::MAC_B)->assertOk()->json();
        $this->assertEqualsWithDelta(28800, $replyA['reply:Session-Timeout'], 2);
        $this->assertEqualsWithDelta(28800, $replyB['reply:Session-Timeout'], 2);

        $grants = Grant::query()->where('type', GrantType::SiteCode)->get();
        $this->assertCount(2, $grants);
        $this->assertEqualsCanonicalizing([self::MAC_A, self::MAC_B], $grants->map(fn (Grant $g) => $g->activeDevice()->mac)->all());
        $this->assertSame([$this->site->id], $grants[0]->siteIds());
        $this->assertSame('Test Guest', $grants[0]->label);
        $this->assertTrue($grants[0]->isAllowedWhileOffline());
        Process::assertNothingRan();

        // The same device again: same grant, time not restarted.
        $this->travel(1)->hours();
        $again = $this->redeem($code, self::MAC_A)->assertOk()->viewData('fields');
        $reply = $this->authorize($again['username'], $again['password'], self::MAC_A)->assertOk()->json();
        $this->assertEqualsWithDelta(25200, $reply['reply:Session-Timeout'], 2);
        $this->assertSame(2, Grant::query()->where('type', GrantType::SiteCode)->count());

        // Guest list: method filter and per-device grant.
        $this->admin()->get('/admin/guests?method=site_code')->assertOk()->assertSee(__('portal.guests.methods.site_code'));
    }

    public function test_normal_code_stays_single_device(): void
    {
        $grant = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin]);
        $this->assertFalse($grant->isAllowedWhileOffline());
        $a = $this->redeem($grant->code, self::MAC_A)->viewData('fields');
        $this->authorize($a['username'], $a['password'], self::MAC_A)->assertOk();
        $b = $this->redeem($grant->code, self::MAC_B)->viewData('fields');
        $this->authorize($b['username'], $b['password'], self::MAC_B)->assertOk();

        $this->assertSame(self::MAC_B, $grant->activeDevice()->mac);
        $this->assertSame(1, $grant->devices()->whereNull('unbound_at')->count());
    }

    public function test_site_code_is_rejected_at_another_site_when_expired_or_deactivated(): void
    {
        $siteCode = $this->siteCode(3600, validUntil: now()->addDay());

        $this->redeem($siteCode->code, self::MAC_A, 'site-b', 'https://hotspot-b.example.com/login')->assertSessionHasErrors('code');
        $this->assertSame(0, Grant::query()->count());

        $this->travel(2)->days();
        $this->redeem($siteCode->code, self::MAC_A)->assertForbidden()->assertSee(__('portal.guest.error_pages.expired.title'));

        $fresh = $this->siteCode(3600);
        app(SiteCodeManager::class)->deactivate($fresh);
        $this->redeem($fresh->code, self::MAC_B)->assertForbidden()->assertSee(__('portal.guest.error_pages.expired.title'));
        $this->assertSame(0, Grant::query()->count());
    }

    public function test_max_devices_is_enforced_when_set(): void
    {
        $code = $this->siteCode(3600, maxDevices: 2)->code;

        $this->redeem($code, self::MAC_A)->assertOk()->assertViewHas('fields');
        $this->redeem($code, self::MAC_B)->assertOk()->assertViewHas('fields');
        $this->withHeader('Accept-Language', 'de');
        $this->redeem($code, self::MAC_C)->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.site_code_full.title', [], 'de'));
        // A device that already has a slot can log in again.
        $this->redeem($code, self::MAC_A)->assertOk()->assertViewHas('fields');
        $this->assertSame(2, Grant::query()->count());

        // A slot frees up when a device's time is over.
        $this->travel(61)->minutes();
        $this->redeem($code, self::MAC_C)->assertOk()->assertViewHas('fields');
    }

    public function test_unlimited_when_max_devices_is_empty(): void
    {
        $code = $this->siteCode(3600)->code;
        foreach (range(1, 6) as $i) {
            $this->redeem($code, sprintf('AA:BB:CC:00:01:%02d', $i))->assertOk()->assertViewHas('fields');
        }
        $this->assertSame(6, Grant::query()->where('type', GrantType::SiteCode)->count());
    }

    public function test_deactivation_keeps_devices_and_disconnect_all_ends_them(): void
    {
        $siteCode = $this->siteCode(3600);
        $a = $this->redeem($siteCode->code, self::MAC_A)->viewData('fields');
        $this->authorize($a['username'], $a['password'], self::MAC_A)->assertOk();
        $this->accounting('sess-a', $a['username'], self::MAC_A);

        $this->admin()->post("/admin/sites/{$this->site->id}/site-code/deactivate");
        $grant = Grant::sole();
        $this->assertTrue($grant->isUsable());
        Process::assertNothingRan();

        $this->admin()->post("/admin/sites/{$this->site->id}/site-code/end-devices", ['site_code' => $siteCode->id])
            ->assertSessionHas('status');
        $this->assertNotNull($grant->refresh()->revoked_at);
        Process::assertRan(fn (PendingProcess $p) => str_contains($p->input, 'Acct-Session-Id = "sess-a"'));
        $audit = AuditLog::query()->where('action', 'site_code.disconnect_all')->sole();
        $this->assertSame(1, $audit->data['count']);

        // Another site's code cannot be addressed through this site.
        $foreign = app(SiteCodeManager::class)->generate($this->other, 3600, null, null);
        $this->admin()->post("/admin/sites/{$this->site->id}/site-code/end-devices", ['site_code' => $foreign->id])->assertNotFound();
    }

    public function test_notice_prints_both_qr_codes_in_a4_and_a6(): void
    {
        $siteCode = $this->siteCode(86400, validUntil: now()->addMonth());

        $response = $this->admin()->get("/admin/sites/{$this->site->id}/site-code/print")->assertOk()
            ->assertSee($siteCode->code)->assertSee('Guest WiFi')->assertSee('size: A4', false)
            ->assertSee(__('portal.site_codes.notice.step_wifi', [], 'de'))->assertSee(__('portal.site_codes.notice.step_sign_in', [], 'it'))
            ->assertSee(__('portal.site_codes.notice.text', [], 'fr'))->assertSee(__('portal.site_codes.notice.text', [], 'en'))
            ->assertDontSee(__('portal.site_codes.no_ssid'));
        $this->assertSame(2, substr_count($response->getContent(), '<svg xmlns'));
        $this->assertSame('https://hotspot.example.com/login?dst='.urlencode('https://portal.example.com/portal?site=site-a&code='.$siteCode->code),
            $response->viewData('signInUrl'));

        $this->admin()->get("/admin/sites/{$this->site->id}/site-code/print?format=a6")->assertOk()->assertSee('size: A6', false);
        $this->assertSame(2, AuditLog::query()->where('action', 'site_code.printed')->count());

        // No SSID: no Wi-Fi QR, a hint instead.
        app(SiteCodeManager::class)->generate($this->other, 3600, null, null);
        $this->admin()->get("/admin/sites/{$this->other->id}/site-code/print")->assertOk()->assertSee(__('portal.site_codes.no_ssid'));
        $this->admin()->get("/admin/sites/{$this->other->id}/site-code/print?format=a6")->assertOk();
    }

    public function test_portal_prefills_the_code_without_skipping_name_and_terms(): void
    {
        $siteCode = $this->siteCode(3600);
        $query = ['site' => 'site-a', 'mac' => self::MAC_A, 'ip' => '10.5.50.10', 'link-login-only' => 'https://hotspot.example.com/login'];

        $this->onZone('guest')->get('/portal?'.http_build_query([...$query, 'link-orig' => 'http://example.com/', 'code' => '123 456']))
            ->assertOk()->assertSee('value="123456"', false);

        // Code inside the router's link-orig (notice QR via the hotspot login page).
        $this->flushSession();
        $this->onZone('guest')->get('/portal?'.http_build_query([...$query,
            'link-orig' => 'https://portal.example.com/portal?site=site-a&code='.$siteCode->code]))
            ->assertOk()->assertSee('value="'.$siteCode->code.'"', false);

        // Prefilled code alone is not a login: name and terms are still required.
        $this->onZone('guest')->post('/portal/code', ['code' => $siteCode->code])
            ->assertSessionHasErrors(['first_name', 'last_name', 'terms']);
        $this->assertSame(0, Grant::query()->count());

        // Other link-orig URLs are ignored.
        $this->flushSession();
        $this->onZone('guest')->get('/portal?'.http_build_query([...$query, 'link-orig' => 'https://example.com/?code=654321']))
            ->assertOk()->assertDontSee('value="654321"', false);
    }

    public function test_new_codes_do_not_collide_with_an_active_site_code(): void
    {
        $siteCode = $this->siteCode(3600);
        $inUse = new \ReflectionMethod(CodeGenerator::class, 'inUse');

        $this->assertTrue($inUse->invoke(app(CodeGenerator::class), $siteCode->code));
        app(SiteCodeManager::class)->deactivate($siteCode);
        $this->assertFalse($inUse->invoke(app(CodeGenerator::class), $siteCode->code));
    }

    private function siteCode(int $duration, ?Carbon $validUntil = null, ?int $maxDevices = null): SiteCode
    {
        return app(SiteCodeManager::class)->generate($this->site, $duration, $validUntil, $maxDevices, $this->manager);
    }

    private function admin(): static
    {
        return $this->onZone('admin')->actingAs($this->manager);
    }

    private function redeem(string $code, string $mac, string $site = 'site-a', string $loginUrl = 'https://hotspot.example.com/login'): TestResponse
    {
        $this->onZone('guest')->get('/portal?'.http_build_query([
            'site' => $site, 'mac' => $mac, 'ip' => '10.5.50.10',
            'link-login-only' => $loginUrl, 'link-orig' => 'http://example.com/',
        ]));

        return $this->onZone('guest')->post('/portal/code', [
            'first_name' => 'Test', 'last_name' => 'Guest', 'terms' => '1', 'code' => $code,
            'terms_version' => TermsVersion::current(app()->getLocale())?->id,
        ]);
    }

    private function authorize(string $user, string $password, string $mac): TestResponse
    {
        return $this->radius('authorize', ['User-Name' => $user, 'User-Password' => $password, 'Calling-Station-Id' => $mac]);
    }

    private function accounting(string $sessionId, string $user, string $mac): TestResponse
    {
        return $this->radius('accounting', ['Acct-Status-Type' => 'Start', 'Acct-Session-Id' => $sessionId, 'User-Name' => $user,
            'Calling-Station-Id' => $mac, 'Framed-IP-Address' => '10.5.50.10']);
    }

    private function radius(string $endpoint, array $attributes): TestResponse
    {
        $attributes['FreeRADIUS-Client-Shortname'] = $this->nas->shortname();
        $body = array_map(fn ($v) => ['type' => 'string', 'value' => [(string) $v]], $attributes);

        return $this->onZone('internal')
            ->withHeader('Authorization', 'Basic '.base64_encode('radius:test-internal-token'))
            ->postJson('/internal/radius/'.$endpoint, $body);
    }
}
