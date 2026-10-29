<?php

namespace App\Federation;

use App\Support\LocalInstance;
use Illuminate\Support\Facades\DB;

/**
 * Local change log (CONCEPT.md §10.3): every write of a replicated row appends one event with the
 * full row as payload and the next sequence number of this instance. Called inside the transaction
 * of the write (Replicated trait), so row and event commit together.
 */
class Outbox
{
    public const TABLE = 'federation_events';

    public function __construct(private LocalInstance $instance) {}

    /** Record the current state of a row (also a tombstone) as a local event. */
    public function record(string $table, string $id): void
    {
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null) {
            return;
        }
        $row = (array) $row;
        $origin = $this->instance->id();

        DB::table(self::TABLE)->insert([
            'origin' => $origin,
            'seq' => $this->nextSeq($origin),
            'table' => $table,
            'record_id' => $id,
            'version' => (string) $row['version'],
            'payload' => self::encode($row),
            'proto' => Federation::FEDERATION_PROTOCOL,
            'sig' => null,
            'created_at' => now(),
        ]);
    }

    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function nextSeq(string $origin): int
    {
        return (int) DB::table(self::TABLE)->where('origin', $origin)->max('seq') + 1;
    }
}
