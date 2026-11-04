<?php

namespace Tests\Feature\Federation;

use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Models\AuditLog;
use App\Models\Site;
use App\Nas\Generic\GenericSettings;
use App\Nas\Generic\Presets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The mapping of a generic RADIUS captive-portal site (experimental, decision 2026-11-04, v0.28.0) replicates
 * with the site; the handoff secret travels only encrypted with the federation data key, and like the UniFi
 * controller the mapping changes only by the hub or the site's home instance (it decides where the one-time
 * credentials go).
 */
class GenericReplicationTest extends TestCase
{
    use FederatedHttp, RefreshDatabase, SimulatesInstances;

    private const SECRET = 'uam-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
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

    public function test_mapping_round_trip_keeps_the_encrypted_secret_and_follows_the_site_rules(): void
    {
        $idA = $this->idOf('a');
        $site = $this->on('a', function () use ($idA) {
            $site = Site::create(['slug' => 'sg', 'name' => 'Site G', 'driver' => 'generic_radius', 'home_instance_id' => $idA, 'settings' => []]);
            $site->update(['settings' => GenericSettings::mergeInto($site, ['preset' => Presets::COOVACHILLI] + Presets::PRESETS[Presets::COOVACHILLI],
                self::SECRET)]);

            return $site;
        });
        $this->syncRound();

        foreach (['hub', 'b'] as $name) {
            $this->assertSame(self::SECRET, $this->on($name, fn () => GenericSettings::secret(Site::find($site->id))), $name);
            $this->assertSame('http://{uamip}:{uamport}/logon', $this->on($name, fn () => GenericSettings::forSite(Site::find($site->id))['login_url']), $name);
        }
        $payloads = $this->on('a', fn () => DB::table(Outbox::TABLE)->where('table', 'sites')->pluck('payload')->implode("\n"));
        $this->assertStringContainsString('uamport', $payloads);
        $this->assertStringNotContainsString(self::SECRET, $payloads);

        // b is neither hub nor home: pointing the login URL to its own server is dropped.
        $this->on('b', fn () => Site::find($site->id)->update(['settings' => GenericSettings::mergeInto(Site::find($site->id),
            ['login_url' => 'https://attacker.example.net/login'], null)]));
        $this->syncRound();
        foreach (['hub', 'a'] as $name) {
            $this->assertSame('http://{uamip}:{uamport}/logon', $this->on($name, fn () => GenericSettings::forSite(Site::find($site->id))['login_url']), $name);
            $this->assertTrue($this->on($name, fn () => AuditLog::where('action', 'federation.event_refused')->where('subject_id', $site->id)->exists()));
        }
    }
}
