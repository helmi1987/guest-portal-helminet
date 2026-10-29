<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MAC whitelist (CONCEPT.md §5, §11): devices admitted by RouterOS MAC authentication without the
 * portal. Replicated, site restriction like grants (no rows = all sites). Whitelist sessions have no
 * grant: session_summaries.grant_id becomes nullable and points to the entry instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mac_whitelist', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('mac', 17)->index();
            $table->string('comment');
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->ulid('created_by')->nullable();
            $table->replicated();
        });

        Schema::create('mac_whitelist_site', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('mac_whitelist_id')->constrained('mac_whitelist')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->replicated();
        });

        // One entry per MAC (tombstones excluded). Partial indexes: SQLite and PostgreSQL only; on
        // MySQL/MariaDB the validation in the admin UI is the only guard.
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX mac_whitelist_mac_unique ON mac_whitelist (mac) WHERE deleted_at IS NULL');
        }

        Schema::table('session_summaries', function (Blueprint $table) {
            $table->ulid('grant_id')->nullable()->change();
            $table->ulid('mac_whitelist_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Whitelist sessions have no grant (accounting rows cascade).
        DB::table('session_summaries')->whereNull('grant_id')->delete();
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->dropIndex(['mac_whitelist_id']);
            $table->dropColumn('mac_whitelist_id');
        });
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->ulid('grant_id')->nullable(false)->change();
        });
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS mac_whitelist_mac_unique');
        }
        Schema::dropIfExists('mac_whitelist_site');
        Schema::dropIfExists('mac_whitelist');
    }
};
