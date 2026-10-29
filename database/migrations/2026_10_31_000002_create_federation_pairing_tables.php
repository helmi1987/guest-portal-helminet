<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 federation part E (CONCEPT.md §10.2): admin URL per instance (replicated), one-time join tokens
 * and the transport state per directly connected peer (both local).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->string('admin_url')->nullable();
        });

        // Local only: join tokens, stored as sha256 hash, single use, short-lived.
        Schema::create('federation_join_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->ulid('created_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->ulid('used_by_instance_id')->nullable();
            $table->timestamps();
        });

        // Local only: heartbeat and transport state per directly connected peer.
        Schema::create('federation_peers', function (Blueprint $table) {
            $table->ulid('instance_id')->primary();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error')->nullable();
            $table->integer('clock_skew_ms')->nullable();
            $table->unsignedInteger('protocol')->nullable();
            // Highest sequence number per origin stored at the peer, and the peer's applied cursors (acks).
            $table->json('origins')->nullable();
            $table->json('acks')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_peers');
        Schema::dropIfExists('federation_join_tokens');
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn('admin_url');
        });
    }
};
