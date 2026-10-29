<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\Pairing;
use App\Federation\Topology;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\Instance;
use App\Models\LocalSetting;
use App\Models\User;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Hub takeover: a spoke makes itself the hub, the other instances follow only by re-pairing, the old
 * hub notices it was replaced and can come back as a normal instance.
 */
class TakeoverTest extends TestCase
{
    use FederatedHttp, RefreshDatabase, SimulatesInstances;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
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
        $this->syncRound();
    }

    private function syncRound(): void
    {
        foreach (['hub', 'a', 'b', 'hub', 'a', 'b'] as $name) {
            $this->on($name, fn () => Artisan::call('portal:federation:sync'));
        }
    }

    private function grantOn(string $name, string $code): Grant
    {
        return $this->on($name, fn () => Grant::create(['type' => GrantType::Code, 'code' => $code, 'duration_s' => 60,
            'expiry_mode' => ExpiryMode::FirstLogin]));
    }

    /** Admin of $name re-pairs with $hub through the web page. */
    private function repair(string $name, string $hub): void
    {
        $token = $this->on($hub, fn () => app(Pairing::class)->createToken(null)['token']);
        $pin = $this->on($hub, fn () => Instance::find(app(LocalInstance::class)->id())->cert_fingerprint);
        $this->on($name, function () use ($token, $pin, $hub) {
            $this->actingAs(User::query()->where('role', 'admin')->first() ?? User::factory()->admin()->create());
            $this->onZone('admin')->get('/admin/federation/repair')->assertOk();
            $this->onZone('admin')->post('/admin/federation/repair', ['url' => "https://$hub.example.com:9443", 'token' => $token,
                'fingerprint' => $pin])->assertRedirect('/admin/federation')->assertSessionHas('status', __('portal.federation.repaired'));
        });
    }

    public function test_promote_repair_and_return_of_the_old_hub(): void
    {
        $oldHub = $this->idOf('hub');
        $idA = $this->idOf('a');
        $this->on('hub', fn () => User::factory()->admin()->create(['email' => 'admin@example.com']));
        $this->syncRound();
        $this->down = ['hub'];

        // Only an admin, confirmed with the instance's name.
        $this->on('a', function () {
            $this->actingAs(User::factory()->create()); // manager
            $this->onZone('admin')->post('/admin/federation/promote', ['confirm_name' => 'A'])->assertForbidden();
            $this->actingAs(User::where('email', 'admin@example.com')->sole());
            $this->onZone('admin')->get('/admin/federation')->assertOk()->assertSee(__('portal.federation.promote'));
            $this->onZone('admin')->post('/admin/federation/promote', ['confirm_name' => 'wrong'])->assertSessionHasErrors('confirm_name');
            $this->onZone('admin')->post('/admin/federation/promote', ['confirm_name' => 'A'])
                ->assertSessionHas('status', __('portal.federation.promoted'));
        });
        $this->assertSame($idA, $this->on('a', fn () => app(Topology::class)->hubId()));
        $this->assertNotNull($this->rowOn('a', 'instances', $oldHub)['deleted_at']);
        $this->assertTrue($this->on('a', fn () => AuditLog::where('action', 'federation.promoted')->exists()));

        // b gets a's events but not the hub change until its admin re-pairs.
        $this->syncRound();
        $this->assertSame($oldHub, $this->on('b', fn () => app(Topology::class)->hubId()));
        $this->assertNull($this->rowOn('b', 'instances', $oldHub)['deleted_at']);
        // The offline rule follows the hub b knows: unreachable old hub -> limited mode.
        Carbon::setTestNow(now()->addMinutes(3));
        $this->assertTrue($this->on('b', fn () => app(Topology::class)->limitedMode()));

        $this->repair('b', 'a');
        $this->assertSame($idA, $this->on('b', fn () => app(Topology::class)->hubId()));
        $this->assertNotNull($this->rowOn('b', 'instances', $oldHub)['deleted_at']);
        $this->syncRound();
        $this->assertFalse($this->on('b', fn () => app(Topology::class)->limitedMode()));
        $grant = $this->grantOn('b', '333333');
        $this->syncRound();
        $this->assertNotNull($this->rowOn('a', 'grants', $grant->id));

        // The old hub returns: it learns it was replaced and does not resume as hub.
        $this->down = [];
        $before = $this->grantOn('hub', '444444');
        $this->syncRound();
        $this->assertNotNull($this->on('hub', fn () => LocalSetting::get(Pairing::REMOVED_SETTING)));
        $this->assertNull($this->rowOn('a', 'grants', $before->id));
        $this->on('hub', function () {
            $this->actingAs(User::where('email', 'admin@example.com')->sole());
            $this->onZone('admin')->get('/admin/federation')->assertOk()->assertSee(__('portal.federation.replaced_as_hub'));
        });

        // Re-paired as a normal instance it replicates again (also what it wrote meanwhile).
        $this->repair('hub', 'a');
        $this->assertSame($idA, $this->on('hub', fn () => app(Topology::class)->hubId()));
        $this->assertNull($this->rowOn('a', 'instances', $oldHub)['deleted_at']);
        $this->syncRound();
        $this->assertNotNull($this->rowOn('a', 'grants', $before->id));
        $this->assertNotNull($this->rowOn('b', 'grants', $before->id));
        $this->assertNull($this->on('hub', fn () => LocalSetting::get(Pairing::REMOVED_SETTING)));
    }

    public function test_repair_needs_a_valid_token_and_the_hub(): void
    {
        $token = $this->on('a', fn () => app(Pairing::class)->createToken(null)['token']);
        $pin = $this->on('a', fn () => Instance::find(app(LocalInstance::class)->id())->cert_fingerprint);
        // a is not the hub: refused.
        $this->on('b', function () use ($token, $pin) {
            $this->actingAs(User::factory()->admin()->create());
            $this->onZone('admin')->post('/admin/federation/repair', ['url' => 'https://a.example.com:9443', 'token' => $token,
                'fingerprint' => $pin])->assertSessionHasErrors('repair');
        });
        $this->assertSame($this->idOf('hub'), $this->on('b', fn () => app(Topology::class)->hubId()));
    }
}
