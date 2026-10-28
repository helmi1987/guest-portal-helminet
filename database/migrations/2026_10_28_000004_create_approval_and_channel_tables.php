<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4: push approval requests and notification channels (CONCEPT.md §7.2 C, §11).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Replicated: notification channels (shared config); config holds secrets, stored encrypted.
        Schema::create('channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 20); // notification (sms: M5)
            $table->string('driver', 30);
            $table->string('name');
            $table->text('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->replicated();
        });

        // Local (CONCEPT.md §10.3), but federation-ready: ULID key, origin instance, version and
        // tombstone, so decisions made on another instance can be routed to the origin later.
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('mac', 17)->index();
            $table->string('status', 10)->default('pending')->index();
            $table->string('decided_by')->nullable();
            $table->string('decided_via', 20)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('duration_s')->nullable();
            $table->foreignUlid('grant_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->json('meta')->nullable(); // per channel delivery data, e.g. Telegram message ids
            $table->replicated();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('channels');
    }
};
