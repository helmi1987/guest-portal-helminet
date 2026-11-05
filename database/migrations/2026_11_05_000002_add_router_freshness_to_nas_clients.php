<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outdated router detection (decision 2026-11-05, v0.30.0, CONCEPT.md §9): fingerprint and time of every hotspot
 * page a router fetched, consecutive failed state queries (since when), and what the admins were last notified
 * about. Local status of the serving instance like login_page_fetched_at (Federation::LOCAL_COLUMNS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nas_clients', function (Blueprint $table) {
            $table->text('hotspot_pages')->nullable();
            $table->unsignedInteger('router_state_failures')->default(0);
            $table->timestamp('router_state_failed_since')->nullable();
            $table->text('freshness_notified')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nas_clients', function (Blueprint $table) {
            $table->dropColumn(['hotspot_pages', 'router_state_failures', 'router_state_failed_since', 'freshness_notified']);
        });
    }
};
