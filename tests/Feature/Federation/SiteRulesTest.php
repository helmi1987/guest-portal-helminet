<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\Topology;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\Instance;
use App\Models\NasClient;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\GrantManager;
use App\Services\SiteCodeManager;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Federation part G (local rules): roaming MAC authentication at another site, home instance of a
 * site for one-time credentials, limited mode (hub unreachable: new logins only by SMS or site code).
 */
class SiteRulesTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:21';

    private Site $home;

    private Site $other;

    private NasClient $homeNas;

    private NasClient $otherNas;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
        $settings = ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com'];
        $this->home = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7', 'settings' => $settings]);
        $this->other = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7', 'settings' => $settings]);
        $this->homeNas = $this->home->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        $this->otherNas = $this->other->nasClients()->create(['name' => 'Router B', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799]);
        Process::fake(['*' => Process::result('Received Disconnect-ACK Id 1')]);
    }

    /** Code redeemed at the home site by MAC (grant running, device bound, terms accepted there). */
    private function redeemedGrant(array $siteIds = []): Grant
    {
        $grants = app(GrantManager::class);
        $grant = $grants->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin, 'site_ids' => $siteIds]);
        $guest = Guest::create(['first_name' => 'Rita', 'last_name' => 'Roaming', 'locale' => 'en']);
        TermsAcceptance::create(['guest_id' => $guest->id, 'grant_id' => $grant->id, 'site_id' => $this->home->id,
            'mac' => self::MAC, 'accepted_at' => now()]);
        $grants->issueToken($grant, $guest, $this->home, self::MAC);
        $grants->bindDevice($grant->fresh(), self::MAC, $guest->id);

        return $grant->fresh();
    }

    private function radius(NasClient $nas, string $endpoint, array $attributes): TestResponse
    {
        $attributes['FreeRADIUS-Client-Shortname'] = $nas->shortname();
        $body = array_map(fn ($v) => ['type' => 'string', 'value' => [(string) $v]], $attributes);

        return $this->onZone('internal')->withHeader('Authorization', 'Basic '.base64_encode('radius:test-internal-token'))
            ->postJson('/internal/radius/'.$endpoint, $body);
    }

    private function macAuth(NasClient $nas, string $mac = self::MAC): TestResponse
    {
        return $this->radius($nas, 'authorize', ['User-Name' => $mac, 'User-Password' => '', 'Calling-Station-Id' => $mac]);
    }

    public function test_known_device_roams_to_another_allowed_site_for_the_remaining_time(): void
    {
        $grant = $this->redeemedGrant();
        Carbon::setTestNow(now()->addMinutes(20));

        $this->macAuth($this->otherNas)->assertOk()->assertJson(['reply:Session-Timeout' => 2400]);
        // At its own site the device uses the portal as before; unknown MACs get the portal too.
        $this->macAuth($this->homeNas)->assertStatus(401);
        $this->macAuth($this->otherNas, 'AA:BB:CC:00:00:99')->assertStatus(401);

        // Accounting: the session belongs to the grant and is served here.
        $this->radius($this->otherNas, 'accounting', ['Acct-Status-Type' => 'Start', 'Acct-Session-Id' => 'r1',
            'User-Name' => self::MAC, 'Calling-Station-Id' => self::MAC, 'Framed-IP-Address' => '10.5.50.21'])->assertNoContent();
        $session = SessionSummary::query()->where('site_id', $this->other->id)->sole();
        $this->assertSame($grant->id, $session->grant_id);
        $this->assertNull($session->mac_whitelist_id);
        $this->assertSame(app(LocalInstance::class)->id(), $session->serving_instance_id);
        $this->assertFalse($session->accessEnded());

        // Revoked: no roaming, the session counts as ended (enforced by the scheduler / apply hooks).
        app(GrantManager::class)->revoke($grant);
        $this->macAuth($this->otherNas)->assertStatus(401);
        $this->assertTrue($session->fresh()->accessEnded());
    }

    public function test_admin_disconnect_blocks_roaming_at_that_site_until_the_grant_changes(): void
    {
        $grant = $this->redeemedGrant();
        $this->macAuth($this->otherNas)->assertOk();
        $this->radius($this->otherNas, 'accounting', ['Acct-Status-Type' => 'Start', 'Acct-Session-Id' => 'k1',
            'User-Name' => self::MAC, 'Calling-Station-Id' => self::MAC, 'Framed-IP-Address' => '10.5.50.22'])->assertNoContent();
        $session = SessionSummary::query()->where('site_id', $this->other->id)->sole();

        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->post("/admin/sessions/{$session->id}/disconnect")->assertRedirect();
        $this->assertNotNull($session->fresh()->kicked_at);
        // The router re-authenticates the MAC at once: not accepted again.
        $this->macAuth($this->otherNas)->assertStatus(401);

        // A change of the grant (e.g. extended) lifts the block.
        Carbon::setTestNow(now()->addMinute());
        app(GrantManager::class)->extend($grant->fresh(), 3600);
        $this->macAuth($this->otherNas)->assertOk();
    }

    public function test_roaming_without_expiry_sends_no_session_timeout(): void
    {
        $grant = $this->redeemedGrant();
        $grant->forceFill(['expires_at' => null])->save();
        $response = $this->macAuth($this->otherNas)->assertOk();
        $this->assertArrayNotHasKey('reply:Session-Timeout', $response->json());
    }

    public function test_no_roaming_to_a_site_the_grant_does_not_allow_or_for_unbound_devices(): void
    {
        $this->redeemedGrant([$this->home->id]);
        $this->macAuth($this->otherNas)->assertStatus(401);

        $grant = $this->redeemedGrant();
        $grant->devices()->update(['unbound_at' => now()]); // test setup, raw
        $this->withoutFederationWriteGuard();
        $this->macAuth($this->otherNas)->assertStatus(401);
    }

    public function test_one_time_credentials_are_accepted_only_by_the_home_instance_of_the_site(): void
    {
        $remote = Instance::create(['name' => 'Other']);
        $grant = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin]);
        $guest = Guest::create(['first_name' => 'A', 'last_name' => 'B', 'locale' => 'en']);
        [$token, $password] = app(GrantManager::class)->issueToken($grant, $guest, $this->home, self::MAC);
        $this->home->update(['home_instance_id' => $remote->id]);

        $attributes = ['User-Name' => $token->username, 'User-Password' => $password, 'Calling-Station-Id' => self::MAC];
        $this->radius($this->homeNas, 'authorize', $attributes)->assertStatus(401);
        $this->assertNull(RadiusToken::find($token->id)->used_at);

        $this->home->update(['home_instance_id' => app(LocalInstance::class)->id()]);
        $this->radius($this->homeNas, 'authorize', $attributes)->assertOk();
    }

    public function test_site_form_sets_the_home_instance(): void
    {
        $remote = Instance::create(['name' => 'Branch']);
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->home->id}/edit")->assertOk()
            ->assertSee(__('portal.sites.home_instance'))->assertSee('Branch');
        $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->home->id}", ['name' => 'Site A', 'slug' => 'site-a',
            'driver' => 'mikrotik_ros7', 'home_instance_id' => $remote->id])->assertRedirect();
        $this->assertSame($remote->id, $this->home->fresh()->home_instance_id);
        $this->assertFalse($this->home->fresh()->isServedHere());

        $this->onZone('admin')->actingAs($admin)->post('/admin/sites', ['name' => 'Site C', 'slug' => 'site-c', 'driver' => 'mikrotik_ros7'])
            ->assertRedirect();
        $this->assertSame(app(LocalInstance::class)->id(), Site::where('slug', 'site-c')->sole()->home_instance_id);
    }

    public function test_limited_mode_allows_only_sms_site_codes_and_bound_devices(): void
    {
        $terms = TermsVersion::publish('en', 'Be nice.');
        $hub = Instance::create(['name' => 'HQ', 'federation_url' => 'https://10.0.0.9:9443']);
        Setting::put(Topology::HUB_SETTING, $hub->id); // never heartbeated: unreachable
        $this->assertTrue(app(Topology::class)->limitedMode());

        Channel::create(['kind' => Channel::KIND_NOTIFICATION, 'driver' => 'gotify', 'name' => 'Gotify',
            'config' => ['url' => 'https://gotify.example.com', 'token' => 'x'], 'enabled' => true]);
        $code = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin]);
        $siteCode = app(SiteCodeManager::class)->generate($this->home, 3600, null, null);
        $this->onZone('guest')->get('/portal?'.http_build_query(['site' => 'site-a', 'mac' => self::MAC,
            'link-login-only' => 'https://hotspot.example.com/login']))->assertOk()->assertSee(__('portal.guest.limited_mode'))
            ->assertDontSee(__('portal.guest.approval_request'));
        $submit = fn (string $c) => $this->onZone('guest')->post('/portal/code', ['first_name' => 'Lia', 'last_name' => 'Limited',
            'terms' => '1', 'code' => $c, 'terms_version' => $terms->id]);

        $submit($code->code)->assertSessionHasErrors(['code' => __('portal.guest.errors.limited_mode')]);
        $this->assertNull($code->fresh()->first_login_at);
        $this->onZone('guest')->post('/portal/approval', ['first_name' => 'Lia', 'last_name' => 'Limited', 'terms' => '1',
            'terms_version' => $terms->id])->assertSessionHasErrors(['approval' => __('portal.guest.errors.limited_mode')]);
        $this->assertSame(0, ApprovalRequest::count());
        $submit($siteCode->code)->assertOk()->assertViewHas('fields');
        $this->assertTrue(Grant::query()->where('type', GrantType::Sms)->doesntExist());
        $this->assertTrue((new Grant(['type' => GrantType::Sms]))->isAllowedWhileOffline());

        // A device already bound to the code keeps working; the setting can be switched off; never on the hub.
        app(GrantManager::class)->bindDevice($code, self::MAC, null);
        $submit($code->code)->assertOk()->assertViewHas('fields');
        $other = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin]);
        Setting::put(Topology::OFFLINE_SMS_ONLY_SETTING, false);
        $submit($other->code)->assertOk();
        Setting::put(Topology::OFFLINE_SMS_ONLY_SETTING, true);
        Setting::put(Topology::HUB_SETTING, app(LocalInstance::class)->id());
        $this->assertFalse(app(Topology::class)->limitedMode());
    }
}
