<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replicated: federation members (CONCEPT.md §11).
        Schema::create('instances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('federation_url')->nullable();
            $table->string('cert_fingerprint')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('clock_skew_ms')->nullable();
            $table->replicated();
        });

        // Replicated: federation-wide settings.
        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->replicated();
        });

        // Local only: this instance's own settings (e.g. instance id).
        Schema::create('local_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_settings');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('instances');
    }
};
