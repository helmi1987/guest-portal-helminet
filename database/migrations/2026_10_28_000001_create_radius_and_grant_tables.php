<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2: sites, routers, guests, grants and RADIUS state (CONCEPT.md §11).
 * Tables marked "Replicated" carry ULID keys and the federation columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Replicated: site catalog.
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('driver', 32);
            $table->ulid('home_instance_id')->nullable();
            $table->json('settings')->nullable();
            $table->replicated();
        });

        // Local only: routers (NAS) of a site with their RADIUS secrets.
        Schema::create('nas_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('nas_ip', 45)->unique();
            $table->string('nas_identifier')->nullable();
            $table->text('radius_secret');
            $table->unsignedInteger('coa_port')->default(3799);
            $table->timestamps();
        });

        // Replicated.
        Schema::create('guests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone_e164', 20)->nullable();
            $table->string('locale', 5)->nullable();
            $table->replicated();
        });

        // Replicated.
        Schema::create('grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 16);
            $table->string('code', 6)->nullable()->index();
            $table->string('label')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('duration_s');
            $table->string('expiry_mode', 16);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('redeem_until')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('max_extensions')->nullable();
            $table->unsignedInteger('extensions_used')->default(0);
            $table->string('rate_limit', 64)->nullable();
            $table->ulid('created_by')->nullable();
            $table->replicated();
        });

        // Replicated: site restriction (no rows = all sites).
        Schema::create('grant_site', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('grant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->replicated();
        });

        // Replicated: MAC binding of a grant (1 active device per grant).
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('grant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mac', 17)->index();
            $table->timestamp('bound_at');
            $table->timestamp('unbound_at')->nullable();
            $table->replicated();
        });

        // Replicated: session summaries.
        Schema::create('session_summaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('grant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('serving_instance_id')->nullable();
            $table->string('mac', 17);
            $table->string('ip', 45)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable()->index();
            $table->replicated();
        });

        // Local only: one-time RADIUS credentials (~60 s).
        Schema::create('radius_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('grant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('device_id')->nullable();
            $table->string('username', 32)->unique();
            $table->string('password_hash', 64);
            $table->string('mac', 17);
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Local only: raw accounting per RADIUS session.
        Schema::create('radius_accounting', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('session_summary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nas_client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('acct_session_id', 64);
            $table->string('username', 64);
            $table->timestamp('last_update_at')->nullable();
            $table->string('terminate_cause', 32)->nullable();
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->timestamp('disconnect_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['nas_client_id', 'acct_session_id']);
        });

        // Local only: guest login attempts (rate limit / lockout).
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('mac', 17)->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->ulid('site_id')->nullable();
            $table->string('method', 16);
            $table->boolean('success');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['login_attempts', 'radius_accounting', 'radius_tokens', 'session_summaries', 'devices',
            'grant_site', 'grants', 'guests', 'nas_clients', 'sites'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
