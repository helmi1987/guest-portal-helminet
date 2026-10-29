<?php

use App\Federation\DataKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * M7 federation part B (CONCEPT.md §10.2): replicated secrets move from the local APP_KEY to the
 * federation data key (cast `federated`): TOTP secrets, channel config, OIDC client secret.
 * Values that cannot be decrypted stay unchanged (they were unreadable before as well).
 * Raw updates without new version or outbox event: existing rows predate the change log.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keys = app(DataKey::class);
        $this->convert(fn (string $v) => str_starts_with($v, DataKey::PREFIX) ? null : $keys->encrypt(Crypt::decryptString($v)));
    }

    public function down(): void
    {
        $keys = app(DataKey::class);
        $this->convert(fn (string $v) => str_starts_with($v, DataKey::PREFIX) ? Crypt::encryptString($keys->decrypt($v)) : null);
    }

    /** @param  Closure(string): ?string  $convert  new value, null = unchanged */
    private function convert(Closure $convert): void
    {
        $apply = function (?string $value) use ($convert): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            try {
                return $convert($value);
            } catch (Throwable) {
                return null;
            }
        };

        foreach (DB::table('users')->whereNotNull('totp_secret')->get(['id', 'totp_secret']) as $row) {
            if (($new = $apply($row->totp_secret)) !== null) {
                DB::table('users')->where('id', $row->id)->update(['totp_secret' => $new]);
            }
        }
        foreach (DB::table('channels')->whereNotNull('config')->get(['id', 'config']) as $row) {
            if (($new = $apply($row->config)) !== null) {
                DB::table('channels')->where('id', $row->id)->update(['config' => $new]);
            }
        }
        foreach (DB::table('settings')->where('key', 'oidc_client_secret')->get(['id', 'value']) as $row) {
            $value = $row->value === null ? null : json_decode($row->value, true);
            if (is_string($value) && ($new = $apply($value)) !== null) {
                DB::table('settings')->where('id', $row->id)->update(['value' => json_encode($new)]);
            }
        }
    }
};
