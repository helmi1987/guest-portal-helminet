<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision 2026-11-04 (v0.28.0): a session on a NAS without Disconnect-Request (generic driver, e.g. OPNsense) is
 * marked once its access ended; portal:enforce-grants then no longer dispatches DisconnectSession for it. It is
 * still closed by the Accounting-Stop or as stale after the Session-Timeout. Local table (not replicated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->timestamp('disconnect_skipped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->dropColumn('disconnect_skipped_at');
        });
    }
};
