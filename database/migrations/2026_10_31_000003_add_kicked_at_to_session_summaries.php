<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Federation part G fix (CONCEPT.md §10.4): an admin disconnect is remembered, so roaming MAC
 * authentication does not log the device in again at that site right away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->timestamp('kicked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('session_summaries', function (Blueprint $table) {
            $table->dropColumn('kicked_at');
        });
    }
};
