<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest logout on the portal status page (decision 2026-11-05, v0.29.0, CONCEPT.md §7.1): the session was ended
 * by the guest. Local like kicked_at (session summaries are not replicated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->timestamp('logged_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->dropColumn('logged_out_at');
        });
    }
};
