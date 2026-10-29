<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RADIUS session: of a grant, or of a MAC whitelist entry (grant_id null, CONCEPT.md §5).
 */
#[Fillable(['grant_id', 'mac_whitelist_id', 'site_id', 'serving_instance_id', 'mac', 'ip', 'started_at', 'stopped_at'])]
class SessionSummary extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'stopped_at' => 'datetime'];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    public function macWhitelist(): BelongsTo
    {
        return $this->belongsTo(MacWhitelist::class)->withTrashed();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function accounting(): HasMany
    {
        return $this->hasMany(RadiusAccounting::class);
    }

    /**
     * Access of this session is over: MAC whitelist entry no longer valid for MAC and site, or grant
     * deleted, ended (expired, revoked) or bound to another device.
     */
    public function accessEnded(): bool
    {
        if ($this->mac_whitelist_id !== null) {
            return ! ($this->macWhitelist?->isValidFor($this->mac, $this->site_id) ?? false);
        }
        $grant = $this->relationLoaded('grant') ? $this->grant : $this->grant()->withTrashed()->first();

        return $grant === null || $grant->trashed() || ! $grant->isUsable()
            || $grant->activeDevice()?->mac !== $this->mac;
    }
}
