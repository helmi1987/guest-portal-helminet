<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most one active device per grant. Partial indexes exist in SQLite and PostgreSQL only;
 * on MySQL/MariaDB the per-grant lock in the RADIUS authorize path is the only guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX devices_active_grant_unique ON devices (grant_id) '
                .'WHERE unbound_at IS NULL AND deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS devices_active_grant_unique');
        }
    }
};
