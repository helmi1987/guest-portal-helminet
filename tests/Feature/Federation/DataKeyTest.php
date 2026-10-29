<?php

namespace Tests\Feature\Federation;

use App\Auth\Oidc\OidcSettings;
use App\Federation\DataKey;
use App\Federation\FederatedSecrets;
use App\Models\Channel;
use App\Models\LocalSetting;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Federation part B: federation data key and the `federated` cast.
 */
class DataKeyTest extends TestCase
{
    use RefreshDatabase;

    private function rawUserSecret(User $user): ?string
    {
        return DB::table('users')->where('id', $user->id)->value('totp_secret');
    }

    public function test_secrets_are_stored_with_the_data_key_and_key_id(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $channel = Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook',
            'config' => ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']]);
        OidcSettings::save(OidcSettings::all(), 'oidc-client-secret');

        $kid = app(DataKey::class)->currentId();
        $raw = $this->rawUserSecret($user);
        $this->assertStringStartsWith(DataKey::PREFIX.$kid.':', $raw);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->totp_secret);

        $rawConfig = DB::table('channels')->where('id', $channel->id)->value('config');
        $this->assertSame($kid, DataKey::keyIdOf($rawConfig));
        $this->assertStringNotContainsString('hook-secret', $rawConfig);
        $this->assertSame('hook-secret', $channel->fresh()->conf('secret'));

        $this->assertSame($kid, DataKey::keyIdOf(Setting::get(OidcSettings::SECRET_SETTING)));
        $this->assertSame('oidc-client-secret', OidcSettings::clientSecret());

        // The key ring itself is encrypted with the local APP_KEY.
        $ring = LocalSetting::get(DataKey::SETTING);
        $this->assertSame($kid, $ring['current']);
        $this->assertSame(32, strlen(base64_decode(Crypt::decryptString($ring['keys'][$kid]))));
    }

    public function test_saving_without_change_keeps_the_ciphertext(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $raw = $this->rawUserSecret($user);

        $fresh = $user->fresh();
        $fresh->totp_secret = 'JBSWY3DPEHPK3PXP';
        $this->assertFalse($fresh->isDirty('totp_secret'));
        $fresh->forceFill(['name' => 'Renamed'])->save();
        $this->assertSame($raw, $this->rawUserSecret($user));
    }

    public function test_rotation_keeps_old_keys_readable_and_reencrypts_as_events(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $channel = Channel::create(['kind' => 'sms', 'driver' => 'clickatell', 'name' => 'SMS', 'config' => ['api_key' => 'k1']]);
        OidcSettings::save(OidcSettings::all(), 'oidc-client-secret');
        $keys = app(DataKey::class);
        $old = $keys->currentId();
        $events = DB::table('federation_events')->count();

        $new = app(FederatedSecrets::class)->rotate();

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $keys->currentId());
        $this->assertEqualsCanonicalizing([$old, $new], $keys->knownIds());
        $this->assertSame($new, DataKey::keyIdOf($this->rawUserSecret($user)));
        $this->assertSame($new, DataKey::keyIdOf(DB::table('channels')->where('id', $channel->id)->value('config')));
        $this->assertSame($new, DataKey::keyIdOf(Setting::get(OidcSettings::SECRET_SETTING)));
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->totp_secret);
        $this->assertSame('k1', $channel->fresh()->conf('api_key'));
        $this->assertSame('oidc-client-secret', OidcSettings::clientSecret());
        // User, channel and setting rewritten as normal changes.
        $this->assertSame($events + 3, DB::table('federation_events')->count());
        // Old ciphertexts stay readable.
        $legacy = $keys->encrypt('x');
        $keys->rotate();
        $this->assertSame('x', $keys->decrypt($legacy));
        $this->assertSame(3, app(FederatedSecrets::class)->reencrypt());
    }

    public function test_shared_key_decrypts_on_another_instance_unknown_key_fails(): void
    {
        $keys = app(DataKey::class);
        $kid = $keys->currentId();
        $value = $keys->encrypt('shared-secret');
        $material = $keys->key($kid);

        // "Other instance": empty ring, then the federation key imported (pairing, later part).
        LocalSetting::query()->whereKey(DataKey::SETTING)->delete();
        $other = new DataKey;
        $other->rotate();
        try {
            $other->decrypt($value);
            $this->fail('Unknown key id must not decrypt.');
        } catch (DecryptException) {
        }
        $other->import($kid, $material, makeCurrent: true);
        $this->assertSame('shared-secret', $other->decrypt($value));
    }

    public function test_migration_converts_app_key_values_and_back(): void
    {
        $this->withoutFederationWriteGuard();
        $now = now();
        DB::table('users')->insert(['id' => '01JUSER0000000000000000000', 'name' => 'Legacy', 'email' => 'legacy@example.com',
            'password' => 'x', 'role' => 'admin', 'totp_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('channels')->insert(['id' => '01JCHAN0000000000000000000', 'kind' => 'sms', 'driver' => 'clickatell',
            'name' => 'SMS', 'config' => Crypt::encryptString(json_encode(['api_key' => 'k1'])), 'enabled' => true,
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('settings')->insert(['id' => '01JSETT0000000000000000000', 'key' => OidcSettings::SECRET_SETTING,
            'value' => json_encode(Crypt::encryptString('oidc-secret')), 'created_at' => $now, 'updated_at' => $now]);
        $migration = require database_path('migrations/2026_10_30_000002_encrypt_replicated_secrets_with_data_key.php');

        $migration->up();
        $user = User::find('01JUSER0000000000000000000');
        $this->assertNotNull(DataKey::keyIdOf($user->getRawOriginal('totp_secret')));
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->totp_secret);
        $this->assertSame('k1', Channel::find('01JCHAN0000000000000000000')->conf('api_key'));
        $this->assertSame('oidc-secret', OidcSettings::clientSecret());
        $migration->up(); // idempotent
        $this->assertSame('JBSWY3DPEHPK3PXP', User::find('01JUSER0000000000000000000')->totp_secret);

        $migration->down();
        $this->assertSame('JBSWY3DPEHPK3PXP', Crypt::decryptString(DB::table('users')->value('totp_secret')));
        $this->assertSame(['api_key' => 'k1'], json_decode(Crypt::decryptString(DB::table('channels')->value('config')), true));
        $this->assertSame('oidc-secret', Crypt::decryptString(json_decode(DB::table('settings')->value('value'), true)));
    }
}
