<?php

namespace Tests;

use App\Federation\Applier;
use App\Federation\Federation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only guard (SQLite): triggers log every insert/update of a replicated table; afterwards every
 * written (table, id, version) must have an outbox or applied event, and an update that changes
 * any column must also change the version. Catches query-builder writes that bypass the outbox.
 */
final class FederationWriteGuard
{
    public static function install(): void
    {
        DB::statement('CREATE TABLE _federation_writes (tbl TEXT, id TEXT, version TEXT, kind TEXT)');
        // Conflict rules of the apply engine change rows without a new version on purpose.
        DB::connection()->getPdo()->sqliteCreateFunction('fed_resolving', fn () => Applier::isResolving() ? 1 : 0, 0);
        foreach (array_keys(Federation::TABLES) as $table) {
            DB::unprepared("CREATE TRIGGER _fedguard_{$table}_ins AFTER INSERT ON \"{$table}\" BEGIN "
                ."INSERT INTO _federation_writes VALUES ('{$table}', NEW.id, NEW.version, 'insert'); END");
            $changed = collect(Schema::getColumnListing($table))->reject(fn ($c) => $c === 'version')
                ->map(fn ($c) => "NEW.\"$c\" IS NOT OLD.\"$c\"")->implode(' OR ');
            DB::unprepared("CREATE TRIGGER _fedguard_{$table}_upd AFTER UPDATE ON \"{$table}\" BEGIN "
                ."INSERT INTO _federation_writes VALUES ('{$table}', NEW.id, NEW.version, "
                ."CASE WHEN NEW.version IS OLD.version AND ($changed) AND NOT fed_resolving() THEN 'same_version' ELSE 'update' END); END");
        }
    }

    /** @return list<string> */
    public static function violations(): array
    {
        $rows = DB::select('SELECT w.tbl, w.id, w.version, w.kind FROM _federation_writes w '
            ."WHERE w.kind = 'same_version' OR NOT EXISTS (SELECT 1 FROM federation_events e "
            .'WHERE e."table" = w.tbl AND e.record_id = w.id AND e.version IS w.version)');

        return array_map(fn ($r) => "{$r->kind} {$r->tbl}#{$r->id} version {$r->version}", $rows);
    }
}
