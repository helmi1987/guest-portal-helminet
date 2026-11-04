<?php

namespace Tests\Feature\Federation;

use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Models\AuditLog;
use App\Models\Site;
use App\Nas\Unifi\UnifiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The UniFi controller of a site (experimental, decision 2026-11-04) replicates with the site; API key and
 * password travel only encrypted with the federation data key, and like the directory the controller
 * changes only by the hub or the site's home instance (it decides where the credentials go).
 */
class UnifiReplicationTest extends TestCase
{
    use FederatedHttp, RefreshDatabase, SimulatesInstances;

    private const KEY = 'test-api-key-0123456789';

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

    public function test_controller_round_trip_keeps_the_encrypted_key_and_follows_the_site_rules(): void
    {
        $idA = $this->idOf('a');
        $site = $this->on('a', function () use ($idA) {
            $site = Site::create(['slug' => 'su', 'name' => 'Site U', 'driver' => 'unifi', 'home_instance_id' => $idA, 'settings' => []]);
            $site->update(['settings' => UnifiSettings::mergeInto($site, ['url' => 'https://unifi.example.com', 'mode' => 'api_key'],
                ['api_key' => self::KEY])]);

            return $site;
        });
        $this->syncRound();

        foreach (['hub', 'b'] as $name) {
            $this->assertSame(self::KEY, $this->on($name, fn () => UnifiSettings::secret(Site::find($site->id), 'api_key')), $name);
            $this->assertTrue($this->on($name, fn () => UnifiSettings::isConfigured(Site::find($site->id))), $name);
        }
        $payloads = $this->on('a', fn () => DB::table(Outbox::TABLE)->where('table', 'sites')->pluck('payload')->implode("\n"));
        $this->assertStringContainsString('unifi.example.com', $payloads);
        $this->assertStringNotContainsString(self::KEY, $payloads);

        // b is neither hub nor home: pointing the controller to its own server is dropped.
        $this->on('b', fn () => Site::find($site->id)->update(['settings' => UnifiSettings::mergeInto(Site::find($site->id),
            ['url' => 'https://attacker.example.net'], [])]));
        $this->syncRound();
        foreach (['hub', 'a'] as $name) {
            $this->assertSame('https://unifi.example.com', $this->on($name, fn () => UnifiSettings::forSite(Site::find($site->id))['url']), $name);
            $this->assertTrue($this->on($name, fn () => AuditLog::where('action', 'federation.event_refused')->where('subject_id', $site->id)->exists()));
        }
    }
}
