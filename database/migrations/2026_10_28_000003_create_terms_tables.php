<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3: versioned terms of use per language, guest acceptances, router certificate token per NAS.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Replicated: every saved change is a new version (per locale).
        Schema::create('terms_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('locale', 5);
            $table->unsignedInteger('number'); // "version" is the replication column
            $table->text('body');
            $table->timestamp('published_at');
            $table->replicated();
            $table->unique(['locale', 'number']);
        });

        // Replicated: which terms version a guest accepted for which grant.
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('terms_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('grant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mac', 17)->nullable();
            $table->timestamp('accepted_at')->index();
            $table->replicated();
        });

        // Local only: token for the router certificate download (encrypted, like the RADIUS secret).
        Schema::table('nas_clients', function (Blueprint $table) {
            $table->text('cert_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nas_clients', fn (Blueprint $table) => $table->dropColumn('cert_token'));
        Schema::dropIfExists('terms_acceptances');
        Schema::dropIfExists('terms_versions');
    }
};
