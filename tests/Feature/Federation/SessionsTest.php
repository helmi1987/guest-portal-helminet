<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Federation\Pairing;
use App\Jobs\DisconnectSession;
use App\Models\Device;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\MacWhitelist;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Services\GrantManager;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Federation part G: apply hooks disconnect local sessions after replicated changes (also conflict
 * rules), sessions of other instances in the guest list (live query through the hub) and remote
 * disconnect at the serving instance.
 */
class SessionsTest extends TestCase
{
    use FederatedHttp, RefreshDatabase, SimulatesInstances;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
        Bus::fake([DisconnectSession::class]);
        $this->makeInstances('hub', 'a', 'b');
        $pin = $this->giveCertificate('hub');
        $this->giveCertificate('a');
        $this->giveCertificate('b');
        $this->routeFederation(['hub', 'a', 'b']);
        foreach (['a', 'b'] as $spoke) {
            $token = $this->on('hub', fn () => app(Pairing::class)->createToken(null)['token']);
            $this->assertSame(0, $this->on($spoke, fn () => Artisan::call('portal:federation:join', [
                'url' => 'https://hub.example.com:9443', 'token' => $token, 'fingerprint' => $pin])), Artisan::output());
        }
        $this->site = $this->on('hub', fn () => Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7']));
        $this->syncAllPeers();
    }

    private function syncAllPeers(): void
    {
        foreach (['hub', 'a', 'b', 'hub', 'a', 'b'] as $name) {
            $this->on($name, fn () => Artisan::call('portal:federation:sync'));
        }
    }

    private function codeOn(string $name): Grant
    {
        return $this->on($name, fn () => app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin]));
    }

    /** Open session served by $name for the grant and MAC (device bound there). */
    private function sessionOn(string $name, Grant $grant, string $mac, ?string $guestId = null): SessionSummary
    {
        return $this->on($name, function () use ($grant, $mac, $guestId) {
            $grant = Grant::findOrFail($grant->id);
            $grant->update(['first_login_at' => $grant->first_login_at ?? now(), 'expires_at' => $grant->expires_at ?? now()->addHour()]);
            app(GrantManager::class)->bindDevice($grant, $mac, $guestId);

            return SessionSummary::create(['grant_id' => $grant->id, 'site_id' => $this->site->id, 'mac' => $mac,
                'serving_instance_id' => app(LocalInstance::class)->id(), 'ip' => '10.5.50.30', 'started_at' => now()]);
        });
    }

    private function assertDisconnected(SessionSummary $session): void
    {
        Bus::assertDispatched(DisconnectSession::class, fn (DisconnectSession $job) => $job->sessionSummaryId === $session->id);
    }

    public function test_replicated_revocation_disconnects_the_session_at_the_serving_instance(): void
    {
        $grant = $this->codeOn('a');
        $this->assertNotNull($this->rowOn('b', 'grants', $grant->id));
        $session = $this->sessionOn('b', $grant, 'AA:BB:CC:00:00:31');
        Bus::assertNotDispatched(DisconnectSession::class);

        $this->on('a', fn () => app(GrantManager::class)->revoke(Grant::findOrFail($grant->id)));
        $this->assertNotNull($this->rowOn('b', 'grants', $grant->id)['revoked_at']);
        $this->assertDisconnected($session);
    }

    public function test_device_unbound_by_the_conflict_rule_is_disconnected(): void
    {
        $grant = $this->codeOn('hub');
        // Partition: the same code redeemed at b and later at a with different devices.
        $this->down = ['hub'];
        $old = $this->sessionOn('b', $grant, 'AA:BB:CC:00:00:41');
        Carbon::setTestNow(now()->addMinute());
        $this->sessionOn('a', $grant, 'AA:BB:CC:00:00:42');
        Bus::assertNotDispatched(DisconnectSession::class);

        $this->down = [];
        $this->syncAllPeers();
        $this->assertNotNull($this->on('b', fn () => Device::where('mac', 'AA:BB:CC:00:00:41')->sole()->unbound_at));
        $this->assertDisconnected($old);
    }

    public function test_whitelist_change_disconnects_and_renews_the_radius_cache(): void
    {
        $entry = $this->on('hub', fn () => MacWhitelist::create(['mac' => 'AA:BB:CC:00:00:51', 'comment' => 'Printer', 'active' => true]));
        $this->assertNotNull($this->on('b', fn () => MacWhitelist::findValidCached('AA:BB:CC:00:00:51', $this->site->id)));
        $session = $this->on('b', fn () => SessionSummary::create(['mac_whitelist_id' => $entry->id, 'site_id' => $this->site->id,
            'mac' => 'AA:BB:CC:00:00:51', 'serving_instance_id' => app(LocalInstance::class)->id(), 'started_at' => now()]));

        $this->on('hub', fn () => MacWhitelist::findOrFail($entry->id)->update(['active' => false]));
        $this->assertDisconnected($session);
        $this->assertNull($this->on('b', fn () => MacWhitelist::findValidCached('AA:BB:CC:00:00:51', $this->site->id)));
    }

    public function test_guest_list_shows_sessions_of_other_instances_and_disconnects_through_the_hub(): void
    {
        $grant = $this->codeOn('hub');
        $guest = $this->on('hub', function () use ($grant) {
            $guest = Guest::create(['first_name' => 'Remo', 'last_name' => 'Te', 'locale' => 'en']);
            TermsAcceptance::create(['guest_id' => $guest->id, 'grant_id' => $grant->id, 'site_id' => $this->site->id,
                'mac' => 'AA:BB:CC:00:00:61', 'accepted_at' => now()]);

            return $guest;
        });
        $session = $this->sessionOn('b', $grant, 'AA:BB:CC:00:00:61', $guest->id);
        $this->assertNotNull($this->on('a', fn () => Guest::where('first_name', 'Remo')->first()));

        $this->on('a', function () use ($session) {
            $this->actingAs(User::factory()->admin()->create());
            $this->onZone('admin')->get('/admin/guests')->assertOk()->assertSee('Remo')->assertSee('10.5.50.30')
                ->assertSee(__('portal.guests.served_by', ['name' => 'B']))
                ->assertSee(route('admin.sessions.disconnect-remote', [$session->serving_instance_id, $session->id], false));

            $this->onZone('admin')->post("/admin/instances/{$session->serving_instance_id}/sessions/{$session->id}/disconnect")
                ->assertSessionHas('status', __('portal.guests.disconnect_sent'));
        });
        $this->assertDisconnected($session);

        // Serving instance unreachable: clear messages.
        $this->down = ['b'];
        $this->on('a', function () use ($session) {
            $this->onZone('admin')->get('/admin/guests')->assertOk()
                ->assertSee(__('portal.guests.remote_unreachable', ['names' => 'B']));
            // The unreachable instance is not asked again right away (the list stays fast).
            $this->assertTrue(Cache::has('federation:live-down:'.$session->serving_instance_id));
            $this->onZone('admin')->post("/admin/instances/{$session->serving_instance_id}/sessions/{$session->id}/disconnect")
                ->assertSessionHasErrors(['session' => __('portal.guests.remote_unreachable_disconnect', ['name' => 'B'])]);
        });
    }
}
