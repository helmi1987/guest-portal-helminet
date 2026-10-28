<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw accounting of one RADIUS session (local only).
 */
#[Fillable(['session_summary_id', 'nas_client_id', 'acct_session_id', 'username', 'last_update_at',
    'terminate_cause', 'bytes_in', 'bytes_out', 'disconnect_sent_at'])]
class RadiusAccounting extends Model
{
    protected $table = 'radius_accounting';

    protected function casts(): array
    {
        return ['last_update_at' => 'datetime', 'disconnect_sent_at' => 'datetime'];
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(SessionSummary::class, 'session_summary_id');
    }

    public function nasClient(): BelongsTo
    {
        return $this->belongsTo(NasClient::class);
    }
}
