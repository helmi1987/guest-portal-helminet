<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6: TOTP two-factor login for admin UI users and the audit log (CONCEPT.md §9, §11).
 * Both tables are replicated (users R, audit_logs R).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // totp_secret (encrypted) exists since M1; it counts only once confirmed.
            $table->timestamp('totp_confirmed_at')->nullable();
            // Last accepted TOTP time step: a code cannot be used twice (replay).
            $table->unsignedBigInteger('totp_last_step')->nullable();
            // JSON list of hashed single-use recovery codes.
            $table->text('recovery_codes')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->nullable()->index();
            // Display label of the actor (user name, Telegram user, "link", "cli", attempted email).
            $table->string('actor')->nullable();
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->replicated();
            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_confirmed_at', 'totp_last_step', 'recovery_codes']);
        });
    }
};
