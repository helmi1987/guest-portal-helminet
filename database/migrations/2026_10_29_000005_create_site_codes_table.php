<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site codes (CONCEPT.md §8): one shared 6-digit code per site, usable by any number of devices.
 * Replicated and site-scoped; regenerating deactivates the previous row (kept as history). Each
 * device login creates its own grant (type site_code) that points to the site code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('code', 6)->index();
            $table->unsignedInteger('duration_s');
            $table->timestamp('valid_until')->nullable();
            $table->unsignedInteger('max_devices')->nullable();
            $table->boolean('active')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->ulid('deactivated_by')->nullable();
            $table->replicated();
        });

        Schema::table('grants', function (Blueprint $table) {
            $table->ulid('site_code_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->dropIndex(['site_code_id']);
            $table->dropColumn('site_code_id');
        });
        Schema::dropIfExists('site_codes');
    }
};
