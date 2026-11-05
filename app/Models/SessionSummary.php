<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RADIUS session: of a grant, or of a MAC whitelist entry (grant_id null, CONCEPT.md §5). kicked_at: disconnected
 * by an admin; logged_out_at: ended by the guest on the portal status page (decision 2026-11-05). Local, not replicated.
 */
#[Fillable(['grant_id', 'mac_whitelist_id', 'site_id', 'serving_instance_id', 'mac', 'ip', 'started_at', 'stopped_at', 'kicked_at', 'disconnect_skipped_at', 'logged_out_at'])]
class SessionSummary extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'stopped_at' => 'datetime', 'kicked_at' => 'datetime', 'disconnect_skipped_at' => 'datetime',
            'logged_out_at' => 'datetime'];
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

    /** Acct-Terminate-Cause of a logout by the user (RFC 2866: User-Request = 1), as name or number. */
    public const USER_REQUEST = ['User-Request', '1'];

    /** Ended by the guest: logout on the portal status page, or a router-side logout (Acct-Terminate-Cause User-Request). */
    public function loggedOut(): bool
    {
        return $this->logged_out_at !== null
            || ($this->stopped_at !== null && $this->accounting()->whereIn('terminate_cause', self::USER_REQUEST)->exists());
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
