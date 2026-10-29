<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M7 federation part A (CONCEPT.md §10.3, §11): change log of replicated rows (local and foreign
 * events), cursors per origin, and deterministic setting ids (derived from the key).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Local only: outbox of this instance plus events received from other origins (relay, catch-up).
        Schema::create('federation_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('origin');
            $table->unsignedBigInteger('seq');
            $table->string('table', 64);
            $table->string('record_id', 64);
            $table->string('version', 64)->index();
            $table->json('payload');
            $table->unsignedInteger('proto');
            $table->text('sig')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['origin', 'seq']);
            $table->index(['table', 'record_id']);
        });

        // Local only: last applied sequence number per origin; a paused origin is not applied.
        Schema::create('peer_cursors', function (Blueprint $table) {
            $table->ulid('origin')->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->timestamps();
        });

        foreach (DB::table('settings')->get(['id', 'key']) as $row) {
            DB::table('settings')->where('id', $row->id)->update(['id' => Setting::idFor($row->key)]);
        }
    }

    public function down(): void
    {
        // Setting ids stay deterministic (any ULID-shaped id is valid).
        Schema::dropIfExists('peer_cursors');
        Schema::dropIfExists('federation_events');
    }
};
