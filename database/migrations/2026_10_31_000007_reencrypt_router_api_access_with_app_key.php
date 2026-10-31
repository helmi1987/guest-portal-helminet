<?php

use App\Federation\DataKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Router API access is local to the instance and encrypted with APP_KEY (CONCEPT.md §9, decision
 * 2026-10-31). Rows written before stored api_user/api_password encrypted with the federation data
 * key: re-encrypt them with APP_KEY. Values already readable with APP_KEY stay as they are. Values
 * readable with neither (e.g. data key unknown here) remove the API access of the router, so the
 * admin enters it again. Raw writes of local columns: no model events, no replication. Values are
 * never logged.
 */
return new class extends Migration
{
    private const FIELDS = ['api_user', 'api_password'];

    public function up(): void
    {
        $rows = DB::table('nas_clients')->where(fn ($q) => $q->whereNotNull('api_user')->orWhereNotNull('api_password'))
            ->orderBy('id')->get(['id', ...self::FIELDS]);
        foreach ($rows as $row) {
            $update = [];
            foreach (self::FIELDS as $field) {
                $value = $row->{$field};
                if ($value === null || $this->readableWithAppKey($value)) {
                    continue;
                }
                $plain = $this->decryptWithDataKey($value);
                if ($plain === null) {
                    $update = ['api_host' => null, 'api_type' => null, 'api_port' => null, 'api_user' => null, 'api_password' => null, 'api_pin' => null];
                    Log::warning('Router API access cannot be decrypted and was removed; enter it again in the site form.', ['nas' => $row->id]);
                    break;
                }
                $update[$field] = Crypt::encryptString($plain);
            }
            if ($update !== []) {
                DB::table('nas_clients')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        // Not reversible: APP_KEY values stay readable by the current code.
    }

    private function readableWithAppKey(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function decryptWithDataKey(string $value): ?string
    {
        if (! str_starts_with($value, DataKey::PREFIX)) {
            return null;
        }
        try {
            return app(DataKey::class)->decrypt($value);
        } catch (Throwable) {
            return null;
        }
    }
};
