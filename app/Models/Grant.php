<?php

namespace App\Models;

use App\Enums\ExpiryMode;
use App\Enums\GrantStatus;
use App\Enums\GrantType;
use App\Models\Concerns\HasSiteLinks;
use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Access grant (CONCEPT.md §8). A code is a grant created in advance.
 */
#[Fillable(['type', 'code', 'label', 'note', 'duration_s', 'expiry_mode', 'valid_from', 'redeem_until',
    'expires_at', 'first_login_at', 'revoked_at', 'max_extensions', 'extensions_used', 'rate_limit', 'created_by'])]
class Grant extends Model
{
    use HasSiteLinks, Replicated;

    protected function casts(): array
    {
        return [
            'type' => GrantType::class,
            'expiry_mode' => ExpiryMode::class,
            'duration_s' => 'integer',
            'valid_from' => 'datetime',
            'redeem_until' => 'datetime',
            'expires_at' => 'datetime',
            'first_login_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_extensions' => 'integer',
            'extensions_used' => 'integer',
        ];
    }

    public function siteLinks(): HasMany
    {
        return $this->hasMany(GrantSite::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(SessionSummary::class);
    }

    public function activeDevice(): ?Device
    {
        return $this->devices()->whereNull('unbound_at')->latest('bound_at')->first();
    }

    public function status(?Carbon $now = null): GrantStatus
    {
        $now ??= now();

        return match (true) {
            $this->revoked_at !== null => GrantStatus::Revoked,
            $this->expires_at !== null && $this->expires_at->lte($now) => GrantStatus::Expired,
            $this->valid_from !== null && $this->valid_from->gt($now) => GrantStatus::Pending,
            $this->first_login_at !== null => GrantStatus::Active,
            $this->redeem_until !== null && $this->redeem_until->lte($now) => GrantStatus::Lapsed,
            default => GrantStatus::Unused,
        };
    }

    public function isUsable(?Carbon $now = null): bool
    {
        return in_array($this->status($now), [GrantStatus::Unused, GrantStatus::Active], true);
    }

    /** Remaining wall-clock seconds (for Session-Timeout); null when not started. */
    public function remainingSeconds(?Carbon $now = null): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, (int) ($now ?? now())->diffInSeconds($this->expires_at, false));
    }

    public function canExtend(): bool
    {
        return $this->max_extensions === null || $this->extensions_used < $this->max_extensions;
    }

    /** SQL equivalent of status(). */
    public function scopeWithStatus(Builder $query, GrantStatus $status): void
    {
        $now = now();
        $running = fn (Builder $q) => $q->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
        $started = fn (Builder $q) => $running($q)->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now));

        match ($status) {
            GrantStatus::Revoked => $query->whereNotNull('revoked_at'),
            GrantStatus::Expired => $query->whereNull('revoked_at')->where('expires_at', '<=', $now),
            GrantStatus::Pending => $running($query)->where('valid_from', '>', $now),
            GrantStatus::Active => $started($query)->whereNotNull('first_login_at'),
            GrantStatus::Lapsed => $started($query)->whereNull('first_login_at')->where('redeem_until', '<=', $now),
            GrantStatus::Unused => $started($query)->whereNull('first_login_at')
                ->where(fn ($q) => $q->whereNull('redeem_until')->orWhere('redeem_until', '>', $now)),
        };
    }

    /** Grants whose time is over or which were revoked. */
    public function scopeEnded(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now()));
    }
}
