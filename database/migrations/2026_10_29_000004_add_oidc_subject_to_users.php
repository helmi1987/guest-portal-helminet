<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.0: OIDC login for the admin UI (CONCEPT.md §9). The user is matched by issuer + subject
 * ("<iss> <sub>") after the first login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_subject', 512)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oidc_subject']);
            $table->dropColumn('oidc_subject');
        });
    }
};
