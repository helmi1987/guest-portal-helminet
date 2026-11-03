<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social login (CONCEPT.md §7.2 D, §11, decision 2026-11-03): provider, the provider's subject (user ID)
 * and the email it reported, per guest row. Replicated like the other guest columns; an instance older
 * than 0.22.0 drops unknown columns of incoming events (and cannot read grants of type social), so all
 * instances of a federation must run 0.22.0 or later before social login is switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('social_provider', 20)->nullable();
            $table->string('social_subject', 191)->nullable();
            $table->string('email', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn(['social_provider', 'social_subject', 'email']);
        });
    }
};
