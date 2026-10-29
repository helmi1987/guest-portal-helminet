<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5: SMS one-time passwords and sent SMS (CONCEPT.md §7.2 B, §11). SMS providers are stored in
 * `channels` (kind sms).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Local (CONCEPT.md §10.3), federation-ready like approval_requests.
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('mac', 17)->index();
            $table->string('phone_e164', 20)->index();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('sends')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->foreignUlid('grant_id')->nullable()->constrained()->nullOnDelete();
            $table->replicated();
        });

        // Local send log: cost caps and delivery result per message.
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('otp_challenge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mac', 17)->nullable()->index();
            $table->string('phone_e164', 20)->index();
            $table->string('status', 20)->default('queued'); // queued, accepted, failed
            $table->string('provider_id')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('otp_challenges');
    }
};
