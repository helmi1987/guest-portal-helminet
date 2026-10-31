<?php

namespace Tests\Feature;

use App\Federation\DataKey;
use App\Federation\FederatedSecrets;
use App\Federation\Federation;
use App\Federation\Outbox;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use App\Nas\MikrotikRos7Driver;
use App\Services\VoucherQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Optional guest Wi-Fi password of the setup assistant and the comment keys of the generated RouterOS
 * objects (CONCEPT.md §9, decision 2026-10-31).
 */
class WifiPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NETWORK = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan30-guest', 'subnet' => '10.30.0.0/24',
        'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2'];

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://portal.example.com']);
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot-a.guest.example.com'], 'radius_address' => '10.0.0.10']]);
    }

    private function save(array $extra): TestResponse
    {
        $admin = User::factory()->admin()->create();

        return $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", self::NETWORK + $extra);
    }

    private function rawSetup(): array
    {
        return json_decode(DB::table('sites')->where('id', $this->site->id)->value('settings'), true)['setup'] ?? [];
    }

    public function test_password_is_validated_stored_encrypted_and_never_audited(): void
    {
        foreach (['short12', str_repeat('a', 64), "umlaut\u{e4}pass", "tab\tpassword"] as $bad) {
            $this->save(['wifi_password' => $bad])->assertSessionHasErrors('wifi_password');
        }
        $this->assertArrayNotHasKey('wifi_password', $this->rawSetup());

        $password = 'Sec "ret\\ $pass?';
        $this->save(['wifi_password' => $password, 'wifi_password_shared' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $raw = $this->rawSetup();
        $this->assertNotSame($password, $raw['wifi_password']);
        $this->assertStringNotContainsString('ret', DB::table('sites')->where('id', $this->site->id)->value('settings'));
        $this->assertSame($password, $this->site->fresh()->wifiPassword());
        $audit = AuditLog::query()->where('action', 'site.setup_network')->latest('id')->first();
        $this->assertSame('wpa', $audit->data['wifi_security']);
        $this->assertStringNotContainsString('ret', json_encode($audit->data));
        $this->assertStringNotContainsString($raw['wifi_password'], json_encode($audit->data));

        // Empty field keeps the stored password; the page never renders it into the input.
        $this->save(['wifi_password' => '', 'wifi_password_shared' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($password, $this->site->fresh()->wifiPassword());
        $admin = User::factory()->admin()->create();
        $page = $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee(__('portal.setup.network.wifi_password_stored'))->assertSee('name="wifi_password_clear"', false);
        $this->assertDoesNotMatchRegularExpression('/name="wifi_password"[^>]*value=/', $page->getContent());

        // Checkbox removes it: open SSID again.
        $this->save(['wifi_password_clear' => '1', 'wifi_password_shared' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($this->site->fresh()->wifiPassword());
        $this->assertArrayNotHasKey('wifi_password', $this->rawSetup());
        $this->assertSame('open', AuditLog::query()->where('action', 'site.setup_network')->latest('id')->first()->data['wifi_security']);
    }

    public function test_password_survives_a_data_key_rotation(): void
    {
        $this->save(['wifi_password' => 'rotate-me-123', 'wifi_password_shared' => '1'])->assertSessionHasNoErrors();
        $before = $this->rawSetup()['wifi_password'];

        app(FederatedSecrets::class)->rotate();

        $this->assertNotSame($before, $this->rawSetup()['wifi_password']);
        $this->assertSame('rotate-me-123', $this->site->fresh()->wifiPassword());
    }

    public function test_security_profile_gets_wpa_with_an_escaped_passphrase_in_every_wifi_mode(): void
    {
        $password = 'a"b\\c$d?e [f] {g};';
        $escaped = 'a\\"b\\\\c\\$d\\?e [f] {g};';
        foreach (['capsman1' => ['/caps-man', 'authentication-types=wpa2-psk encryption=aes-ccm'],
            'capsman2' => ['/interface wifi', 'authentication-types=wpa2-psk,wpa3-psk'],
            'local' => ['/interface wifi', 'authentication-types=wpa2-psk,wpa3-psk']] as $mode => [$path, $auth]) {
            $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => ['wifi_mode' => $mode] + self::NETWORK
                + ['wifi_password' => Site::encryptWifiPassword($password)]])]);
            $site = $this->site->fresh();
            $wifi = $site->driver()->setupCommands($site, [])['network']['wifi'];

            $this->assertStringContainsString(
                "{$path} security set [find where name=sec-guest && comment=\"guest-portal:wifi:sec-guest\"] {$auth} passphrase=\"{$escaped}\"", $wifi, $mode);
            // Add-or-update by name plus own key (or legacy comment): re-running updates the passphrase
            $this->assertStringContainsString(":if ([:len [{$path} security find where name=sec-guest && (comment=\"guest-portal:wifi:sec-guest\" || comment=\"guest-portal\" || comment~\"^guest-portal: \")]] = 0) do={", $wifi);
            $this->assertStringNotContainsString(' unset ', $wifi);
            $this->assertStringNotContainsString($password, $wifi);

            // Without a password: open, a password set earlier is removed from the own profile only
            $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => ['wifi_mode' => $mode] + self::NETWORK])]);
            $site = $this->site->fresh();
            $open = $site->driver()->setupCommands($site, [])['network']['wifi'];
            $this->assertStringContainsString("{$path} security unset [find where name=sec-guest && comment=\"guest-portal:wifi:sec-guest\"] passphrase", $open);
            $this->assertStringContainsString("{$path} security unset [find where name=sec-guest && comment=\"guest-portal:wifi:sec-guest\"] authentication-types", $open);
            $this->assertStringNotContainsString('passphrase=', $open);
        }
    }

    public function test_every_generated_comment_key_is_listed_and_unique(): void
    {
        $nas = NasClient::find($this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799])->id);
        foreach (['capsman1', 'capsman2', 'local'] as $mode) {
            $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => ['wifi_mode' => $mode] + self::NETWORK])]);
            $site = $this->site->fresh();
            $driver = $site->driver();
            $objects = $driver->managedObjects($site);

            $this->assertSame(['radius', 'walled', 'cert', 'dns', 'container', 'net', 'wifi', 'fw', 'api'], array_keys($objects));
            $keys = array_merge(...array_map('array_keys', array_values($objects)));
            $this->assertSame($keys, array_unique($keys));
            foreach ($objects as $block => $entries) {
                foreach ($entries as $key => $entry) {
                    $this->assertMatchesRegularExpression('/^guest-portal:'.$block.':[a-z0-9-]+$/', $key);
                    $this->assertStringStartsWith('/', $entry['path']);
                    $this->assertArrayHasKey('name', $entry);
                }
            }
            $this->assertSame(MikrotikRos7Driver::key('wifi', 'sec-guest'), 'guest-portal:wifi:sec-guest');
            $this->assertSame($mode === 'capsman1' ? '/caps-man security' : '/interface wifi security', $objects['wifi']['guest-portal:wifi:sec-guest']['path']);
            $this->assertSame($mode === 'local', isset($objects['wifi']['guest-portal:wifi:wifi-guest']));
            $this->assertSame(['path' => '/ip pool', 'name' => 'guest-pool'], $objects['net']['guest-portal:net:pool']);

            $all = $driver->configScript($site, $nas).implode("\n", array_merge(...array_map('array_values',
                array_values($driver->setupCommands($site, ['bridge' => 'containers'])))));
            preg_match_all('/comment="(guest-portal[^"]*)"/', $all, $m);
            $used = array_unique($m[1]);
            // Exact legacy comment "guest-portal" appears only inside the removal/re-run guards
            $this->assertEqualsCanonicalizing($keys, array_values(array_diff($used, ['guest-portal'])), $mode);
            // Outside the find conditions no add/set writes a legacy comment
            $outside = $all;
            do {
                $outside = preg_replace('/\[[^\[\]]*\]/', '', $outside, -1, $n);
            } while ($n > 0);
            $this->assertDoesNotMatchRegularExpression('/comment="guest-portal(: [^"]*)?"/', $outside);
            // Never a bare prefix match on comments
            $this->assertStringNotContainsString('comment~"^guest-portal"', $all);
            $this->assertDoesNotMatchRegularExpression('/comment~"\^guest-portal:[^ ]/', $all);
        }
    }

    public function test_voucher_qr_and_print_sheet_carry_the_password(): void
    {
        $this->assertSame('WIFI:T:WPA;S:Guest WiFi;P:pa\\;ss\\:wo\\,rd\\"\\\\;;', VoucherQr::wifiPayload('Guest WiFi', 'pa;ss:wo,rd"\\'));
        $this->assertSame('WIFI:T:WPA;S:Guest;P:DEADBEEF;;', VoucherQr::wifiPayload('Guest', 'DEADBEEF'));
        $this->assertSame('WIFI:T:WPA;S:Guest;P:12345678;;', VoucherQr::wifiPayload('Guest', '12345678'));
        $this->assertSame('WIFI:S:Guest;T:nopass;;', VoucherQr::wifiPayload('Guest', null));
        $this->assertSame('WIFI:S:Guest;T:nopass;;', VoucherQr::wifiPayload('Guest', ''));

        $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => ['ssid' => 'Guest A',
            'wifi_password' => Site::encryptWifiPassword('voucher-pass-1')]])]);
        $same = Site::create(['slug' => 'b', 'name' => 'B', 'driver' => 'mikrotik_ros7', 'settings' => ['setup' => ['ssid' => 'Guest A']]]);
        $manager = User::factory()->create();

        $response = $this->onZone('admin')->actingAs($manager)->post('/admin/grants', [
            'quantity' => 1, 'duration' => '1d', 'expiry_mode' => 'first_login', 'site_ids' => [$this->site->id],
        ]);
        $grant = Grant::query()->sole();
        $qr = new VoucherQr;
        $this->assertSame(['ssid' => 'Guest A', 'password' => 'voucher-pass-1'], $qr->networkFor($grant->load('siteLinks')));
        $this->assertSame('voucher-pass-1', $qr->passwordFor($grant));
        $this->onZone('admin')->actingAs($manager)->get($response->headers->get('Location'))->assertOk()
            ->assertSee('Guest A')->assertSee(__('portal.grants.wifi_password'))->assertSee('voucher-pass-1')->assertSee('<svg', false);

        // Same SSID, different password on another site: ambiguous for an unrestricted grant (no QR)
        $this->onZone('admin')->actingAs($manager)->post('/admin/grants', ['quantity' => 1, 'duration' => '1d', 'expiry_mode' => 'first_login']);
        $all = Grant::query()->whereKeyNot($grant->id)->sole()->load('siteLinks');
        $this->assertNull((new VoucherQr)->networkFor($all));
        $this->assertNull((new VoucherQr)->ssidFor($all));
        $same->update(['settings' => ['setup' => ['ssid' => 'Guest A', 'wifi_password' => Site::encryptWifiPassword('voucher-pass-1')]]]);
        $this->assertSame('voucher-pass-1', (new VoucherQr)->passwordFor($all));
    }

    private function rawLocal(): ?string
    {
        return DB::table('sites')->where('id', $this->site->id)->value('wifi_password_local');
    }

    public function test_default_is_local_encrypted_with_app_key_and_not_replicated(): void
    {
        $this->save(['wifi_password' => 'local-secret-1'])->assertSessionHasNoErrors();
        $raw = DB::table('sites')->where('id', $this->site->id)->value('settings');
        $this->assertArrayNotHasKey('wifi_password', $this->rawSetup());
        $this->assertSame('wpa', $this->rawSetup()['wifi_security']);
        $this->assertFalse($this->rawSetup()['wifi_password_shared']);
        $this->assertStringNotContainsString('local-secret-1', $raw);
        $this->assertNotNull($this->rawLocal());
        $this->assertSame('local-secret-1', Crypt::decryptString($this->rawLocal()));
        $this->assertSame('local-secret-1', $this->site->fresh()->wifiPassword());
        $this->assertSame(['wifi_password_local'], Federation::localColumns('sites'));

        // Replication payload carries neither the secret nor the local column
        app(Outbox::class)->record('sites', $this->site->id);
        $payload = (string) DB::table('federation_events')->where('table', 'sites')->latest('seq')->value('payload');
        $this->assertStringNotContainsString('local-secret-1', $payload);
        $this->assertStringNotContainsString('wifi_password_local', $payload);
        $this->assertStringNotContainsString($this->rawLocal(), $payload);
        $this->assertStringContainsString('wpa', $payload);
    }

    public function test_toggle_moves_the_value_between_storages(): void
    {
        $this->save(['wifi_password' => 'move-me-1234', 'wifi_password_shared' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($this->rawLocal());
        $this->assertArrayHasKey('wifi_password', $this->rawSetup());

        $this->save([])->assertSessionHasNoErrors(); // checkbox off, empty password: move to local
        $this->assertArrayNotHasKey('wifi_password', $this->rawSetup());
        $this->assertSame('move-me-1234', $this->site->fresh()->wifiPassword());
        $this->assertSame('move-me-1234', Crypt::decryptString($this->rawLocal()));

        $this->save(['wifi_password_shared' => '1'])->assertSessionHasNoErrors(); // back to shared
        $this->assertNull($this->rawLocal());
        $this->assertSame('move-me-1234', $this->site->fresh()->wifiPassword());
        $this->assertSame('move-me-1234', app(DataKey::class)->decrypt($this->rawSetup()['wifi_password']));
        $this->assertSame('wpa', $this->rawSetup()['wifi_security']);
    }

    public function test_a_shared_site_keeps_no_local_copy(): void
    {
        $this->save(['wifi_password' => 'local-first-1'])->assertSessionHasNoErrors();
        $this->assertNotNull($this->rawLocal());

        // Any save of a shared site drops the local column (also outside the setup form)
        $site = $this->site->fresh();
        $site->update(['settings' => array_merge($site->settings, ['setup' => array_merge($site->settings['setup'],
            ['wifi_password_shared' => true, 'wifi_password' => Site::encryptWifiPassword('shared-now-1')])])]);
        $this->assertNull($this->rawLocal());
        $this->assertSame('shared-now-1', $this->site->fresh()->wifiPassword());

        // A stale local value written behind the model's back is ignored while shared and can be cleared
        DB::table('sites')->where('id', $this->site->id)->update(['wifi_password_local' => Crypt::encryptString('stale-pass-1')]);
        $this->assertSame('shared-now-1', $this->site->fresh()->wifiPassword());
        $this->assertSame(1, Site::dropSharedLocalWifiPasswords());
        $this->assertNull($this->rawLocal());
        $this->assertSame(0, Site::dropSharedLocalWifiPasswords());
    }

    public function test_sharing_a_password_that_is_not_on_this_instance_is_refused(): void
    {
        // WPA, password kept local on another instance: ticking "share" with an empty field has no value
        $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => self::NETWORK
            + ['wifi_security' => 'wpa', 'wifi_password_shared' => false]])]);
        $before = $this->rawSetup();
        $events = DB::table('federation_events')->where('table', 'sites')->count();

        $this->save(['wifi_password_shared' => '1'])->assertSessionHasErrors(['wifi_password' => __('portal.setup.network.wifi_password_share_missing')]);
        $this->assertSame($before, $this->rawSetup());
        $this->assertSame($events, DB::table('federation_events')->where('table', 'sites')->count());

        // With a password entered it is shared
        $this->save(['wifi_password' => 'entered-here-1', 'wifi_password_shared' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->rawSetup()['wifi_password_shared']);
        $this->assertSame('entered-here-1', $this->site->fresh()->wifiPassword());

        // An open network has nothing to lose: the flag alone is accepted
        $this->save(['wifi_password_clear' => '1'])->assertSessionHasNoErrors();
        $this->save(['wifi_password_shared' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('open', $this->rawSetup()['wifi_security']);
    }

    public function test_a_shared_flag_without_a_shared_value_never_deletes_the_local_password(): void
    {
        $this->save(['wifi_password' => 'only-copy-123'])->assertSessionHasNoErrors();

        // Flag set without a value (as replicated from another instance): the local copy stays
        $site = $this->site->fresh();
        $site->update(['settings' => array_merge($site->settings, ['setup' => array_merge($site->settings['setup'],
            ['wifi_password_shared' => true])])]);
        $this->assertSame('only-copy-123', Crypt::decryptString($this->rawLocal()));
        $this->assertSame(0, Site::dropSharedLocalWifiPasswords());
        $this->assertSame(0, Site::dropSharedLocalWifiPasswords($this->site->id));
        $this->assertSame('only-copy-123', Crypt::decryptString($this->rawLocal()));

        // Saving the form with "share" ticked and an empty field moves the local value into the shared setting
        $this->save(['wifi_password_shared' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($this->rawLocal());
        $this->assertSame('only-copy-123', app(DataKey::class)->decrypt($this->rawSetup()['wifi_password']));
        $this->assertSame('only-copy-123', $this->site->fresh()->wifiPassword());
    }

    public function test_unavailable_password_gives_no_qr_no_text_and_no_open_network(): void
    {
        // Replicated flag says WPA, but the password is local on another instance
        $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => self::NETWORK
            + ['wifi_security' => 'wpa', 'wifi_password_shared' => false]])]);
        $site = $this->site->fresh();
        $this->assertNull($site->wifiPassword());
        $this->assertTrue($site->wifiPasswordUnavailable());

        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->post('/admin/grants', [
            'quantity' => 1, 'duration' => '1d', 'expiry_mode' => 'first_login', 'site_ids' => [$this->site->id],
        ]);
        $grant = Grant::query()->sole()->load('siteLinks');
        $this->assertNull((new VoucherQr)->networkFor($grant));
        $this->assertNull((new VoucherQr)->ssidFor($grant));
        $this->assertNull((new VoucherQr)->passwordFor($grant));

        $wifi = $site->driver()->setupCommands($site, [])['network']['wifi'];
        $this->assertStringContainsString('not available on this instance', $wifi);
        $this->assertStringNotContainsString('security add', $wifi);
        $this->assertStringNotContainsString('unset', $wifi);

        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee(__('portal.setup.network.wifi_password_unavailable'));

        // Saving without a new password keeps WPA (never silently open); a new one makes it available
        $this->save([])->assertSessionHasNoErrors();
        $this->assertSame('wpa', $this->rawSetup()['wifi_security']);
        $this->assertTrue($this->site->fresh()->wifiPasswordUnavailable());
        $this->save(['wifi_password' => 'now-here-123'])->assertSessionHasNoErrors();
        $this->assertFalse($this->site->fresh()->wifiPasswordUnavailable());
    }
}
