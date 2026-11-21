<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning window of the MAC whitelist (decision 2026-11-21, v0.33.0, CONCEPT.md §5): local to this instance (not
 * replicated). While a window of a site is open, RADIUS accepts every MAC authentication of that site's routers and
 * records the attempts; the admin takes devices over into the whitelist or discards them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_windows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('opened_by')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('learning_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('learning_window_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('mac', 17);
            $table->ulid('nas_client_id')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('count')->default(1);
            // pending | taken | discarded
            $table->string('status', 16)->default('pending');
            $table->ulid('mac_whitelist_id')->nullable();
            $table->timestamps();
            $table->unique(['learning_window_id', 'mac']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_attempts');
        Schema::dropIfExists('learning_windows');
    }
};
