<?php

use App\Federation\DataKey;
use Illuminate\Database\Migrations\Migration;

/**
 * M7 federation (CONCEPT.md §10.2): the first federation data key is created at install/upgrade
 * time, not on first use by concurrent processes.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(DataKey::class)->currentId();
    }

    public function down(): void
    {
        // The key ring stays: values encrypted with it must remain readable.
    }
};
