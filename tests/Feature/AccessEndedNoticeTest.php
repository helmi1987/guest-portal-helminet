<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Services\GrantManager;
use App\Support\Mac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Login page notice for a returning device whose access ended (expired, revoked, disconnected).
 */
class AccessEndedNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:01';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com']]);
    }

    public function test_notice_for_expired_revoked_and_disconnected_access_only(): void
    {
        $grants = app(GrantManager::class);
        $mac = Mac::normalize(self::MAC);

        // Unknown MAC: no notice.
        $this->enter()->assertOk()->assertDontSee('gp-access-ended', false);

        // Active grant bound to the MAC: no notice.
        $grant = $grants->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin, 'site_ids' => [$this->site->id]]);
        $grant->update(['first_login_at' => now(), 'expires_at' => now()->addHour()]);
        $grants->bindDevice($grant, $mac, null);
        $this->enter()->assertDontSee('gp-access-ended', false);

        // Disconnected by an admin (grant still valid).
        $session = SessionSummary::create(['grant_id' => $grant->id, 'site_id' => $this->site->id, 'mac' => $mac,
            'started_at' => now()->subMinutes(10), 'stopped_at' => now(), 'kicked_at' => now()]);
        $this->enter()->assertSee('gp-access-ended', false)->assertSee(__('portal.guest.access_ended.disconnected', [], 'de'))
            ->assertSee(__('portal.guest.access_ended.sign_in_again', [], 'de'))->assertDontSee($grant->code);
        $session->update(['kicked_at' => null]);

        // Expired.
        $grant->update(['expires_at' => now()->subMinutes(5)]);
        $this->enter()->assertSee(__('portal.guest.access_ended.expired', [], 'de'));

        // Revoked (blocked).
        $grants->revoke($grant->fresh());
        $this->enter()->assertSee(__('portal.guest.access_ended.revoked', [], 'de'));

        // Older than the window: no notice.
        $this->travel(25)->hours();
        $this->enter()->assertDontSee('gp-access-ended', false);
        $this->travelBack();

        // Another site: no notice for this grant (site-restricted).
        Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com']]]);
        $this->enter('site-b')->assertDontSee('gp-access-ended', false);

        // A new usable grant on the same MAC hides the notice.
        $new = $grants->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin, 'site_ids' => [$this->site->id]]);
        $new->update(['first_login_at' => now(), 'expires_at' => now()->addHour()]);
        $grants->bindDevice($new, $mac, null);
        $this->enter()->assertDontSee('gp-access-ended', false);
    }

    private function enter(string $site = 'site-a')
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'de')->get('/portal?'.http_build_query([
            'site' => $site, 'mac' => self::MAC, 'link-login-only' => 'https://hotspot.example.com/login',
        ]));
    }
}
