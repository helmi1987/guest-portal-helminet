<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Site restriction of a MAC whitelist entry (replicated row, soft-deleted when removed).
 */
#[Fillable(['mac_whitelist_id', 'site_id'])]
class MacWhitelistSite extends Model
{
    use Replicated;

    protected $table = 'mac_whitelist_site';

    protected static function booted(): void
    {
        // Site changes affect the RADIUS caches of the whitelist.
        foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::$event(fn () => MacWhitelist::flushCache());
        }
    }
}
