<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transport state of a directly connected peer (local only, CONCEPT.md §10.2): last successful
 * heartbeat, clock skew, protocol, the peer's stored origins and applied cursors (acks), and a pause
 * (clock skew, protocol) that stops push and pull with this peer until an admin resumes it.
 */
class FederationPeer extends Model
{
    protected $primaryKey = 'instance_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'paused_at' => 'datetime',
            'clock_skew_ms' => 'integer',
            'protocol' => 'integer',
            'origins' => 'array',
            'acks' => 'array',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class)->withTrashed();
    }

    public static function for(string $instanceId): self
    {
        return self::query()->firstOrNew(['instance_id' => $instanceId]);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /** Applied sequence number of an origin at the peer (null: unknown). */
    public function ack(string $origin): ?int
    {
        $acks = $this->acks ?? [];

        return array_key_exists($origin, $acks) ? (int) $acks[$origin] : null;
    }
}
