<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup assistant status per router (CONCEPT.md §9). `nas_clients` is local (never replicated), so
 * plain nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nas_clients', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('cert_fetched_at')->nullable();
            $table->timestamp('login_page_fetched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nas_clients', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'cert_fetched_at', 'login_page_fetched_at']);
        });
    }
};
