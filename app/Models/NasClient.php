<?php

namespace App\Models;

use App\Casts\Federated;
use App\Models\Concerns\Replicated;
use App\Radius\ClientsFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A router of a site (replicated for recovery, CONCEPT.md §10.3; secrets with the federation data key).
 * Only the site's home instance serves it: FreeRADIUS clients file, RADIUS, downloads, disconnects.
 * last_seen_at, cert_fetched_at and login_page_fetched_at are local status (not replicated).
 */
#[Fillable(['name', 'nas_ip', 'nas_identifier', 'coa_port'])]
#[Hidden(['radius_secret', 'cert_token'])]
class NasClient extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['radius_secret' => Federated::class, 'cert_token' => Federated::class, 'coa_port' => 'integer', 'legacy_id' => 'integer',
            'last_seen_at' => 'datetime', 'cert_fetched_at' => 'datetime', 'login_page_fetched_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (NasClient $client) {
            $client->radius_secret ??= static::generateSecret();
            $client->cert_token ??= static::generateSecret();
        });
        // Keep the FreeRADIUS clients file in sync (restart via the radius-watch service).
        static::saved(fn () => app(ClientsFile::class)->write());
        static::deleted(fn () => app(ClientsFile::class)->write());
    }

    public static function generateSecret(): string
    {
        // Alphanumeric only: safe in the FreeRADIUS clients file and RouterOS scripts.
        return Str::random(32);
    }

    /** Token for the router certificate download; created on demand for routers from before M3. */
    public function certToken(): string
    {
        if ($this->cert_token === null) {
            $this->cert_token = static::generateSecret();
            $this->save();
        }

        return $this->cert_token;
    }

    /**
     * Record a status timestamp (setup assistant) without model events: saving would rewrite the
     * FreeRADIUS clients file. RADIUS contact is written at most once per minute.
     */
    public function markSeen(string $column = 'last_seen_at'): void
    {
        if (! in_array($column, ['last_seen_at', 'cert_fetched_at', 'login_page_fetched_at'], true)
            || ($column === 'last_seen_at' && $this->last_seen_at?->gt(now()->subMinute()))) {
            return;
        }
        $this->{$column} = now();
        static::query()->whereKey($this->id)->toBase()->update([$column => $this->{$column}]);
        $this->syncOriginalAttribute($column);
    }

    /** Client name in the FreeRADIUS clients file; passed back by FreeRADIUS to identify the NAS. */
    public function shortname(): string
    {
        return 'nas-'.$this->id;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Served by this instance: its site's home instance is this one (CONCEPT.md §10.4). */
    public function isServedHere(): bool
    {
        return $this->site !== null && $this->site->isServedHere();
    }

    /**
     * Router by the id in a download URL: ULID, or the number of routers created before the ids
     * became ULIDs (deployed router scripts). Only routers served here.
     */
    public static function findServed(string $id): ?self
    {
        $candidates = ctype_digit($id) && strlen($id) < 19
            ? self::query()->with('site')->where('legacy_id', (int) $id)->get()
            : self::query()->with('site')->whereKey(strtolower($id))->get();

        return $candidates->first(fn (self $nas) => $nas->isServedHere());
    }
}
