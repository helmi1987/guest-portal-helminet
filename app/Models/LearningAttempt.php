<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MAC authentication seen during a learning window (decision 2026-11-21, v0.33.0): local, not replicated. Status
 * pending, taken (MAC whitelist entry created for the site) or discarded (no access for the rest of the window).
 */
#[Fillable(['site_id', 'mac', 'nas_client_id', 'first_seen_at', 'last_seen_at', 'count', 'status', 'mac_whitelist_id'])]
class LearningAttempt extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const TAKEN = 'taken';

    public const DISCARDED = 'discarded';

    protected $attributes = ['status' => self::PENDING, 'count' => 1];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'count' => 'integer'];
    }

    /** Device may stay online: still pending (not taken over or discarded) and its window open. */
    public function allowsAccess(): bool
    {
        return $this->status === self::PENDING && ($this->window?->isOpen() ?? false);
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(LearningWindow::class, 'learning_window_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function nasClient(): BelongsTo
    {
        return $this->belongsTo(NasClient::class);
    }
}
