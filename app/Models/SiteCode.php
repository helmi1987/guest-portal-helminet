<?php

namespace App\Models;

use App\Enums\GrantStatus;
use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Site code (CONCEPT.md §8): one shared 6-digit code per site for any number of devices. Replicated
 * and site-scoped (valid only at its own site). At most one active row per site; regenerating
 * deactivates the previous one, which stays as history. Each device login creates its own grant
 * (type site_code, `grants.site_code_id`) with `duration_s` running from that login.
 */
#[Fillable(['site_id', 'code', 'duration_s', 'valid_until', 'max_devices', 'active', 'created_by', 'deactivated_at', 'deactivated_by'])]
class SiteCode extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return [
            'duration_s' => 'integer',
            'valid_until' => 'datetime',
            'max_devices' => 'integer',
            'active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Per-device grants created by logins with this code. */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by')->withTrashed();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true)->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->active && $this->deactivated_at === null;
    }

    public function isExpired(?Carbon $now = null): bool
    {
        return $this->valid_until !== null && $this->valid_until->lte($now ?? now());
    }

    /** Accepts new logins: active and not past valid_until. Running device grants are not affected. */
    public function isValid(?Carbon $now = null): bool
    {
        return $this->isActive() && ! $this->isExpired($now);
    }

    /** Device grants whose time is still running (they count against max_devices). */
    public function runningGrants(): HasMany
    {
        return $this->grants()->withStatus(GrantStatus::Active);
    }

    public function isFull(): bool
    {
        return $this->max_devices !== null && $this->runningGrants()->count() >= $this->max_devices;
    }

    /** Hook for the federation "HQ unreachable -> SMS login only" rule (M7): site codes stay allowed. */
    public function isAllowedWhileOffline(): bool
    {
        return true;
    }
}
