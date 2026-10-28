<?php

namespace App\Models;

use App\Radius\ClientsFile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A router talking RADIUS to this instance (local only, never replicated).
 */
#[Fillable(['name', 'nas_ip', 'nas_identifier', 'coa_port'])]
#[Hidden(['radius_secret', 'cert_token'])]
class NasClient extends Model
{
    protected function casts(): array
    {
        return ['radius_secret' => 'encrypted', 'cert_token' => 'encrypted', 'coa_port' => 'integer'];
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
            $this->saveQuietly();
        }

        return $this->cert_token;
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
}
