<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest Wi-Fi masters per site and Wi-Fi mode (CONCEPT.md §9, decision 2026-11-01): local column
 * (JSON, mode => list of names), listed in Federation::LOCAL_COLUMNS like the router API access, so it
 * is never replicated and never overwritten by remote events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->text('wifi_masters_local')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('wifi_masters_local');
        });
    }
};
