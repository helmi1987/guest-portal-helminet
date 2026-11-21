<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

/**
 * Learning window of the MAC whitelist per site (decision 2026-11-21, v0.33.0, CONCEPT.md §5). Local to this
 * instance, not replicated. While open (not closed, not expired), RADIUS accepts every MAC authentication of the
 * site's routers (Session-Timeout = remaining window time) and records the attempts; devices not taken over into the
 * whitelist lose access when the window closes or expires (portal:enforce-grants disconnects them).
 */
#[Fillable(['site_id', 'opened_by', 'opened_at', 'expires_at', 'closed_at'])]
class LearningWindow extends Model
{
    use HasUlids;

    /** Selectable durations in minutes. */
    public const DURATIONS = [5, 10, 15, 30, 60];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'expires_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LearningAttempt::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null && $this->expires_at->gt(now());
    }

    public function remainingSeconds(): int
    {
        return $this->isOpen() ? max(0, (int) now()->diffInSeconds($this->expires_at, false)) : 0;
    }

    /** The open window of a site, if any. */
    public static function openFor(?string $siteId): ?self
    {
        return $siteId === null ? null : self::query()->where('site_id', $siteId)->open()->latest('opened_at')->first();
    }

    /** A device of the site may stay online: its window is open and its attempt was not discarded. */
    public static function allows(?string $siteId, string $mac): bool
    {
        $window = self::openFor($siteId);

        return $window !== null && ! $window->attempts()->where('mac', $mac)->where('status', LearningAttempt::DISCARDED)->exists();
    }

    /** Record one MAC authentication of this window (first/last seen, count, router). */
    public function record(string $mac, ?string $nasId): LearningAttempt
    {
        $attempt = $this->attempts()->where('mac', $mac)->first();
        if ($attempt === null) {
            try {
                return $this->attempts()->create(['site_id' => $this->site_id, 'mac' => $mac, 'nas_client_id' => $nasId,
                    'first_seen_at' => now(), 'last_seen_at' => now(), 'count' => 1]);
            } catch (QueryException) {
                // Concurrent first request of the same MAC (unique window + MAC): update that row
                $attempt = $this->attempts()->where('mac', $mac)->firstOrFail();
            }
        }
        $attempt->forceFill(['last_seen_at' => now(), 'count' => $attempt->count + 1, 'nas_client_id' => $nasId ?? $attempt->nas_client_id])->save();

        return $attempt;
    }
}
