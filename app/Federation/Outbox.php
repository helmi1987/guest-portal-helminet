<?php

namespace App\Federation;

use App\Jobs\PushEvents;
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

    private bool $pushScheduled = false;

    public function __construct(private LocalInstance $instance, private SigningKey $signer) {}

    /** Record the current state of a row (also a tombstone) as a local event. */
    public function record(string $table, string $id): void
    {
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null) {
            return;
        }
        $row = array_diff_key((array) $row, array_flip(Federation::localColumns($table)));
        $origin = $this->instance->id();
        $seq = $this->nextSeq($origin);
        $version = (string) $row['version'];
        $payload = self::encode($row);
        $proto = Federation::FEDERATION_PROTOCOL;

        DB::table(self::TABLE)->insert([
            'origin' => $origin,
            'seq' => $seq,
            'table' => $table,
            'record_id' => $id,
            'version' => $version,
            'payload' => $payload,
            'proto' => $proto,
            // Ed25519 signature of the origin: relayed events cannot be forged (CONCEPT.md §10.2).
            'sig' => $this->signer->sign(EventCodec::message($origin, $seq, $table, $id, $version, $proto, $payload)),
            'created_at' => now(),
        ]);
        $this->schedulePush();
    }

    /** Push to the peers once per transaction, after commit (queue job; pull every 30 s is the fallback). */
    private function schedulePush(): void
    {
        if ($this->pushScheduled) {
            return;
        }
        $this->pushScheduled = true;
        DB::afterCommit(function () {
            $this->pushScheduled = false;
            PushEvents::dispatchIfFederated();
        });
        DB::afterRollBack(fn () => $this->pushScheduled = false);
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
