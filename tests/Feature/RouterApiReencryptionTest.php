<?php

namespace Tests\Feature;

use App\Federation\DataKey;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Router API access written before it became local (encrypted with the federation data key) is
 * re-encrypted with APP_KEY by a migration (CONCEPT.md §9, decision 2026-10-31).
 */
class RouterApiReencryptionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_31_000007_reencrypt_router_api_access_with_app_key.php';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7', 'settings' => []]);
    }

    private function router(string $name, string $ip, array $raw): NasClient
    {
        $nas = $this->site->nasClients()->create(['name' => $name, 'nas_ip' => $ip, 'coa_port' => 3799]);
        DB::table('nas_clients')->where('id', $nas->id)->update(['api_host' => $ip, 'api_type' => 'rest', 'api_pin' => null] + $raw);

        return $nas;
    }

    private function raw(NasClient $nas): array
    {
        return (array) DB::table('nas_clients')->where('id', $nas->id)->first();
    }

    public function test_old_data_key_values_are_reencrypted_with_app_key(): void
    {
        $keys = app(DataKey::class);
        $old = $this->router('Old', '10.0.1.1', ['api_user' => $keys->encrypt('portal-ro'), 'api_password' => $keys->encrypt('Old pass 1')]);
        $new = $this->router('New', '10.0.1.2', ['api_user' => Crypt::encryptString('new-ro'), 'api_password' => Crypt::encryptString('New pass 1')]);
        $newRaw = $this->raw($new);
        // Unknown data key (instance never had it) and a value of another APP_KEY: access removed
        $foreign = new Encrypter(random_bytes(32), 'aes-256-cbc');
        $lost = $this->router('Lost', '10.0.1.3', ['api_user' => DataKey::PREFIX.'0badc0de:'.$foreign->encryptString('lost-ro'),
            'api_password' => $keys->encrypt('Lost pass 1')]);
        $garbage = $this->router('Garbage', '10.0.1.4', ['api_user' => Crypt::encryptString('g-ro'), 'api_password' => $foreign->encryptString('G pass 1')]);
        $none = $this->router('None', '10.0.1.5', ['api_host' => null, 'api_type' => null, 'api_user' => null, 'api_password' => null]);
        $events = DB::table('federation_events')->count();

        Log::spy();
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $this->assertSame('portal-ro', Crypt::decryptString($this->raw($old)['api_user']));
        $this->assertSame('Old pass 1', Crypt::decryptString($this->raw($old)['api_password']));
        $this->assertSame('Old pass 1', NasClient::find($old->id)->api_password);
        $this->assertTrue(NasClient::find($old->id)->hasApiAccess());
        $this->assertSame($newRaw, $this->raw($new));
        foreach ([$lost, $garbage] as $nas) {
            $row = $this->raw($nas);
            foreach (['api_host', 'api_type', 'api_port', 'api_user', 'api_password', 'api_pin'] as $column) {
                $this->assertNull($row[$column], $column);
            }
            $this->assertSame($nas->name, $row['name']);
        }
        $this->assertNull($this->raw($none)['api_user']);
        $this->assertSame($events, DB::table('federation_events')->count()); // local columns: nothing replicated

        Log::shouldHaveReceived('warning')->twice()->withArgs(function (string $message, array $context) {
            $text = $message.json_encode($context);
            foreach (['lost-ro', 'Lost pass', 'g-ro', 'G pass'] as $secret) {
                if (str_contains($text, $secret)) {
                    return false;
                }
            }

            return true;
        });

        $migration->up(); // idempotent
        $this->assertSame('Old pass 1', NasClient::find($old->id)->api_password);
    }

    public function test_an_undecryptable_value_does_not_break_the_site_pages(): void
    {
        $nas = $this->router('R', '10.0.1.1', ['api_user' => Crypt::encryptString('portal-ro'),
            'api_password' => (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('Other key 1')]);
        $this->assertNull(NasClient::find($nas->id)->api_password);
        $this->assertFalse(NasClient::find($nas->id)->hasApiAccess());

        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()->assertDontSee('Other key 1');
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk();
    }
}
