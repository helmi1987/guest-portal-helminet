<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest Wi-Fi password kept on one instance (CONCEPT.md §9, decision 2026-10-31): local column,
 * encrypted with APP_KEY (`encrypted` cast), listed in Federation::LOCAL_COLUMNS so it is never in
 * the replication payload and never overwritten by remote events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->text('wifi_password_local')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('wifi_password_local');
        });
    }
};
