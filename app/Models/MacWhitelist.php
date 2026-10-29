<?php

namespace App\Models;

use App\Jobs\DisconnectSession;
use App\Models\Concerns\HasSiteLinks;
use App\Models\Concerns\Replicated;
use App\Support\LocalInstance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Device admitted by RouterOS MAC authentication without the portal (CONCEPT.md §5): no grant,
 * no Session-Timeout unless the entry expires. Site restriction like grants (no links = all sites).
 */
#[Fillable(['mac', 'comment', 'expires_at', 'active', 'created_by'])]
class MacWhitelist extends Model
{
    use HasSiteLinks, Replicated;

    protected $table = 'mac_whitelist';

    private const CACHE_GENERATION = 'mac-whitelist:generation';

    /** Seconds a rejected MAC is not looked up again (changes invalidate it at once). */
    private const NEGATIVE_TTL = 60;

    protected static function booted(): void
    {
        foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::$event(fn () => self::flushCache());
        }
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'active' => 'boolean'];
    }

    public function siteLinks(): HasMany
    {
        return $this->hasMany(MacWhitelistSite::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(SessionSummary::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isExpired(?Carbon $now = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte($now ?? now());
    }

    /** Active, not expired and not deleted. */
    public function isValid(?Carbon $now = null): bool
    {
        return $this->active && ! $this->trashed() && ! $this->isExpired($now);
    }

    public function isValidFor(string $mac, ?string $siteId): bool
    {
        return $this->mac === $mac && $this->isValid() && $this->allowsSite($siteId);
    }

    /** Remaining wall-clock seconds; null when the entry does not expire. */
    public function remainingSeconds(): ?int
    {
        return $this->expires_at === null ? null : max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    public function scopeValid(Builder $query): void
    {
        $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Valid entry for this MAC at this site, if any. Newest version first: concurrent entries for the
     * same MAC on partitioned instances are possible until replication resolves them (M7).
     */
    public static function findValid(string $mac, string $siteId): ?self
    {
        $entry = self::query()->with('siteLinks')->where('mac', $mac)->valid()->orderByDesc('version')->first();

        return $entry?->allowsSite($siteId) ? $entry : null;
    }

    /**
     * findValid() for the RADIUS reject path (every unknown device asks first): a cached "no valid
     * entry at all" flag and a short negative cache per site + MAC. Both are keyed by a generation
     * that every change of an entry or its site links renews, so changes apply immediately.
     */
    public static function findValidCached(string $mac, string $siteId): ?self
    {
        $generation = Cache::get(self::CACHE_GENERATION) ?? self::flushCache();
        if (! Cache::remember("mac-whitelist:$generation:any", 600, fn () => self::query()->valid()->exists())) {
            return null;
        }

        $miss = "mac-whitelist:$generation:miss:$siteId:$mac";
        if (Cache::has($miss)) {
            return null;
        }
        $entry = self::findValid($mac, $siteId);
        if ($entry === null) {
            Cache::put($miss, true, self::NEGATIVE_TTL);
        }

        return $entry;
    }

    /** Invalidate the RADIUS caches (new generation). */
    public static function flushCache(): string
    {
        $generation = Str::random(12);
        Cache::forever(self::CACHE_GENERATION, $generation);

        return $generation;
    }

    /**
     * Disconnect open sessions of this entry served by this instance that are no longer allowed
     * (deactivated, deleted, expired, site removed, MAC changed). Dispatched after commit.
     */
    public function disconnectInvalidSessions(): int
    {
        $this->unsetRelation('siteLinks');
        $sessions = $this->sessions()->whereNull('stopped_at')
            ->where('serving_instance_id', app(LocalInstance::class)->id())->get()
            ->reject(fn (SessionSummary $s) => $this->isValidFor($s->mac, $s->site_id));

        foreach ($sessions as $session) {
            DisconnectSession::dispatch($session->id)->afterCommit();
        }

        return $sessions->count();
    }
}
