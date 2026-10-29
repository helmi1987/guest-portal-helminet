<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Federation (CONCEPT.md §10.3): an origin waiting for a missing parent row is paused after
 * portal.federation.max_wait_attempts / max_wait_minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peer_cursors', function (Blueprint $table) {
            $table->unsignedBigInteger('blocked_seq')->nullable();
            $table->timestamp('blocked_since')->nullable();
            $table->unsignedInteger('blocked_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('peer_cursors', function (Blueprint $table) {
            $table->dropColumn(['blocked_seq', 'blocked_since', 'blocked_attempts']);
        });
    }
};
