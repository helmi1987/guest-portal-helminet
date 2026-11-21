<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning window sessions (decision 2026-11-21, v0.33.0): marked by the recorded attempt they belong to, so only they
 * stay online while their window is open (session summaries are local, CONCEPT.md §11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->ulid('learning_attempt_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->dropIndex(['learning_attempt_id']);
            $table->dropColumn('learning_attempt_id');
        });
    }
};
