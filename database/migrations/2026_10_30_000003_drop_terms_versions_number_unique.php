<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 federation part C (CONCEPT.md §10.3): instances may publish the same terms number per locale
 * concurrently; both versions are kept, so (locale, number) is no longer unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms_versions', function (Blueprint $table) {
            $table->dropUnique(['locale', 'number']);
            $table->index(['locale', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('terms_versions', function (Blueprint $table) {
            $table->dropIndex(['locale', 'number']);
            $table->unique(['locale', 'number']);
        });
    }
};
