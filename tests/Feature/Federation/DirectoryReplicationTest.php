<?php

namespace Tests\Feature\Federation;

use App\Auth\Directory\DirectorySettings;
use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The directory of a site (account login, CONCEPT.md §7.2 E) replicates with the site; the service
 * account password travels only encrypted with the federation data key, and like hotspot hosts and
 * portal URL the directory changes only by the hub or the site's home instance.
 */
class DirectoryReplicationTest extends TestCase
{
    use FederatedHttp, RefreshDatabase, SimulatesInstances;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
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

    public function test_directory_round_trip_keeps_the_encrypted_password_and_follows_the_site_rules(): void
    {
        $idA = $this->idOf('a');
        $site = $this->on('a', function () use ($idA) {
            $site = Site::create(['slug' => 'sa', 'name' => 'Site A', 'driver' => 'mikrotik_ros7', 'home_instance_id' => $idA, 'settings' => []]);
            $site->update(['settings' => DirectorySettings::mergeInto($site, ['enabled' => true, 'host' => 'dc1.example.com',
                'base_dn' => 'DC=example,DC=com', 'bind_dn' => 'CN=svc,DC=example,DC=com'], 'Svc pass 1!')]);

            return $site;
        });
        $this->syncRound();

        foreach (['hub', 'b'] as $name) {
            $this->assertSame('Svc pass 1!', $this->on($name, fn () => DirectorySettings::password(Site::find($site->id))), $name);
            $this->assertTrue($this->on($name, fn () => DirectorySettings::isConfigured(Site::find($site->id))), $name);
        }
        $payloads = $this->on('a', fn () => DB::table(Outbox::TABLE)->where('table', 'sites')->pluck('payload')->implode("\n"));
        $this->assertStringContainsString('dc1.example.com', $payloads);
        $this->assertStringNotContainsString('Svc pass 1!', $payloads);

        // b is neither hub nor home: pointing the directory to its own server is dropped.
        $this->on('b', fn () => Site::find($site->id)->update(['settings' => DirectorySettings::mergeInto(Site::find($site->id),
            ['host' => 'attacker.example.com'], 'stolen')]));
        $this->syncRound();
        foreach (['hub', 'a'] as $name) {
            $this->assertSame('dc1.example.com', $this->on($name, fn () => DirectorySettings::forSite(Site::find($site->id))['host']), $name);
            $this->assertSame('Svc pass 1!', $this->on($name, fn () => DirectorySettings::password(Site::find($site->id))), $name);
            $this->assertTrue($this->on($name, fn () => AuditLog::where('action', 'federation.event_refused')->where('subject_id', $site->id)->exists()));
        }

        // The hub may.
        $this->on('hub', fn () => Site::find($site->id)->update(['settings' => DirectorySettings::mergeInto(Site::find($site->id),
            ['host' => 'dc2.example.com'], null)]));
        $this->syncRound();
        $this->assertSame('dc2.example.com', $this->on('a', fn () => DirectorySettings::forSite(Site::find($site->id))['host']));
        $this->assertSame('Svc pass 1!', $this->on('a', fn () => DirectorySettings::password(Site::find($site->id))));
    }
}
