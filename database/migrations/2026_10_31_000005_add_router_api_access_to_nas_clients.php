<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only router API access per router for the setup assistant's state query (CONCEPT.md §9,
 * decision 2026-10-31). Access fields are replicated with the router (user and password encrypted
 * with the federation data key); the query result is local status of the querying instance
 * (Federation::LOCAL_COLUMNS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nas_clients', function (Blueprint $table) {
            $table->string('api_host')->nullable();
            $table->string('api_type', 16)->nullable();
            $table->unsignedInteger('api_port')->nullable();
            $table->text('api_user')->nullable();
            $table->text('api_password')->nullable();
            $table->string('api_pin', 64)->nullable();
            $table->longText('router_state')->nullable();
            $table->timestamp('router_state_at')->nullable();
            $table->text('router_state_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nas_clients', function (Blueprint $table) {
            $table->dropColumn(['api_host', 'api_type', 'api_port', 'api_user', 'api_password', 'api_pin',
                'router_state', 'router_state_at', 'router_state_error']);
        });
    }
};
