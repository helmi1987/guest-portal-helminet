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
 * last_seen_at, cert_fetched_at, login_page_fetched_at and the router state (router_state*) are local
 * status (not replicated). Read-only router API access (api_*) is replicated; user and password with
 * the federation data key.
 */
#[Fillable(['name', 'nas_ip', 'nas_identifier', 'coa_port', 'api_host', 'api_type', 'api_port', 'api_user', 'api_password', 'api_pin'])]
#[Hidden(['radius_secret', 'cert_token', 'api_user', 'api_password', 'router_state'])]
class NasClient extends Model
{
    use Replicated;

    /** Router API types: RouterOS REST (www-ssl, /rest) or the RouterOS API over TLS (api-ssl). */
    public const API_TYPES = ['rest', 'api-ssl'];

    protected function casts(): array
    {
        return ['radius_secret' => Federated::class, 'cert_token' => Federated::class, 'coa_port' => 'integer', 'legacy_id' => 'integer',
            'last_seen_at' => 'datetime', 'cert_fetched_at' => 'datetime', 'login_page_fetched_at' => 'datetime',
            'api_user' => Federated::class, 'api_password' => Federated::class, 'api_port' => 'integer',
            'router_state' => 'array', 'router_state_at' => 'datetime', 'router_state_error' => 'array'];
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

    /** Read-only router API configured (host, type, user and password); see App\Nas\RouterOs. */
    public function hasApiAccess(): bool
    {
        return $this->api_host !== null && $this->api_host !== '' && in_array($this->api_type, self::API_TYPES, true)
            && $this->api_user !== null && $this->api_user !== '' && $this->api_password !== null;
    }

    /** API port: the configured one or the RouterOS default of the type (www-ssl 443, api-ssl 8729). */
    public function apiPort(): int
    {
        return $this->api_port ?: ($this->api_type === 'api-ssl' ? 8729 : 443);
    }

    /**
     * Store the result of a router state query (local status, no model events: saving would rewrite the
     * FreeRADIUS clients file and replicate). A failed query keeps the previous state and records the error.
     *
     * @param  array{kind: string, detail: string}|null  $error
     */
    public function storeRouterState(?array $state, ?array $error = null): void
    {
        $values = $error === null
            ? ['router_state' => $state, 'router_state_at' => now(), 'router_state_error' => null]
            : ['router_state_error' => $error + ['at' => now()->toIso8601String()]];
        foreach ($values as $column => $value) {
            $this->{$column} = $value;
        }
        $raw = array_intersect_key($this->getAttributes(), $values);
        static::query()->whereKey($this->id)->toBase()->update($raw);
        foreach (array_keys($values) as $column) {
            $this->syncOriginalAttribute($column);
        }
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
