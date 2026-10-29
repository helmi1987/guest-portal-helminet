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
}
