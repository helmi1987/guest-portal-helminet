<?php

use App\Federation\DataKey;
use App\Federation\Outbox;
use App\Support\DeterministicId;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Federation (decision 2026-10-31, CONCEPT.md §10.3): routers (nas_clients, incl. RADIUS secret and
 * certificate token, now with the federation data key) and the certificate sources of every instance
 * are replicated, so a replacement instance is complete after joining. Each instance serves only the
 * routers of sites whose home instance it is.
 *
 * nas_clients get ULID keys (legacy_id keeps the old number: deployed router scripts use it in
 * download URLs); radius_accounting.nas_client_id follows. Existing rows and the certificate sources
 * from local settings are recorded as events of this instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        $keys = app(DataKey::class);
        $reencrypt = function (?string $value) use ($keys): ?string {
            if ($value === null || $value === '' || str_starts_with($value, DataKey::PREFIX)) {
                return $value;
            }
            try {
                return $keys->encrypt(Crypt::decryptString($value));
            } catch (Throwable) {
                return $value;
            }
        };

        // Old table aside (references from radius_accounting follow the rename), new one with ULIDs.
        Schema::rename('nas_clients', 'nas_clients_legacy');
        Schema::create('nas_clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('nas_ip', 45)->index();
            $table->string('nas_identifier')->nullable();
            $table->text('radius_secret');
            $table->unsignedInteger('coa_port')->default(3799);
            $table->text('cert_token')->nullable();
            // Local status of the serving instance (not replicated, see Federation::LOCAL_COLUMNS).
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('cert_fetched_at')->nullable();
            $table->timestamp('login_page_fetched_at')->nullable();
            $table->replicated();
        });
        $map = [];
        $columns = Schema::getColumnListing('nas_clients_legacy');
        foreach (DB::table('nas_clients_legacy')->orderBy('id')->get() as $old) {
            $old = (array) $old;
            $id = strtolower((string) Str::ulid());
            $map[$old['id']] = $id;
            DB::table('nas_clients')->insert([
                'id' => $id,
                'legacy_id' => $old['id'],
                'site_id' => $old['site_id'],
                'name' => $old['name'],
                'nas_ip' => $old['nas_ip'],
                'nas_identifier' => $old['nas_identifier'],
                'radius_secret' => $reencrypt($old['radius_secret']),
                'coa_port' => $old['coa_port'],
                'cert_token' => $reencrypt($old['cert_token'] ?? null),
                'last_seen_at' => in_array('last_seen_at', $columns, true) ? $old['last_seen_at'] : null,
                'cert_fetched_at' => in_array('cert_fetched_at', $columns, true) ? $old['cert_fetched_at'] : null,
                'login_page_fetched_at' => in_array('login_page_fetched_at', $columns, true) ? $old['login_page_fetched_at'] : null,
                'created_at' => $old['created_at'],
                'updated_at' => $old['updated_at'],
            ]);
        }

        Schema::create('radius_accounting_new', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('session_summary_id')->constrained('session_summaries')->cascadeOnDelete();
            $table->foreignUlid('nas_client_id')->nullable()->constrained('nas_clients')->nullOnDelete();
            $table->string('acct_session_id', 64);
            $table->string('username', 64);
            $table->timestamp('last_update_at')->nullable();
            $table->string('terminate_cause', 32)->nullable();
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->timestamp('disconnect_sent_at')->nullable();
            $table->timestamps();
        });
        foreach (DB::table('radius_accounting')->orderBy('id')->get() as $row) {
            $row = (array) $row;
            $row['nas_client_id'] = $row['nas_client_id'] === null ? null : ($map[$row['nas_client_id']] ?? null);
            DB::table('radius_accounting_new')->insert($row);
        }
        Schema::drop('radius_accounting');
        Schema::drop('nas_clients_legacy');
        Schema::rename('radius_accounting_new', 'radius_accounting');
        Schema::table('radius_accounting', function (Blueprint $table) {
            $table->unique(['nas_client_id', 'acct_session_id']);
        });

        // Certificate source per instance and target.
        Schema::create('certificate_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('instance_id')->index();
            $table->string('target', 16);
            $table->string('type', 16)->default('none');
            $table->text('url')->nullable();
            $table->string('name')->nullable();
            $table->text('ca_pem')->nullable();
            $table->text('api_key')->nullable();
            $table->replicated();
        });

        $hasRouters = $map !== [];
        $sources = DB::table('local_settings')->whereIn('key', ['cert_source.portal', 'cert_source.router'])->get();
        if (! $hasRouters && $sources->isEmpty()) {
            return;
        }
        $local = app(LocalInstance::class)->id();
        $hlc = app(Hlc::class);
        $outbox = app(Outbox::class);
        foreach ($map as $id) {
            DB::table('nas_clients')->where('id', $id)->update(['origin_instance_id' => $local, 'version' => $hlc->now()]);
            $outbox->record('nas_clients', $id);
        }
        foreach ($sources as $setting) {
            $value = (array) json_decode((string) $setting->value, true);
            $target = substr($setting->key, strlen('cert_source.'));
            $id = DeterministicId::for("certificate_source:$local:$target");
            DB::table('certificate_sources')->insert([
                'id' => $id, 'instance_id' => $local, 'target' => $target,
                'type' => in_array($value['type'] ?? null, ['none', 'certwarden', 'url'], true) ? $value['type'] : 'none',
                'url' => $value['url'] ?? null, 'name' => $value['name'] ?? null, 'ca_pem' => $value['ca_pem'] ?? null,
                'api_key' => $reencrypt($value['api_key'] ?? null),
                'origin_instance_id' => $local, 'version' => $hlc->now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $outbox->record('certificate_sources', $id);
            DB::table('local_settings')->where('key', $setting->key)->delete();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Not reversible: restore a backup instead.');
    }
};
