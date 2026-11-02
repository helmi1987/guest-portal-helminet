<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest SSID per CAP (CONCEPT.md §9, decision 2026-11-02): radios (radio-mac) selected per CAPsMAN mode
 * that get the guest SSID through an own provisioning rule. Local column (JSON, mode => list of MACs),
 * listed in Federation::LOCAL_COLUMNS like wifi_masters_local: never replicated, never overwritten by
 * remote events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->text('wifi_caps_local')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('wifi_caps_local');
        });
    }
};
