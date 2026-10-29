<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SMS one-time password for a guest device (CONCEPT.md §7.2 B). Only a keyed hash of the code is stored.
 */
#[Fillable(['guest_id', 'site_id', 'mac', 'phone_e164', 'code_hash', 'attempts', 'sends', 'last_sent_at',
    'expires_at', 'verified_at', 'grant_id'])]
class OtpChallenge extends Model
{
    use Replicated;

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sends' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /** Keyed hash bound to the challenge, so equal codes of different challenges differ. */
    public static function hashCode(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key'));
    }

    /** Open for verification: not verified, not expired, tries left. */
    public function isOpen(): bool
    {
        return $this->verified_at === null && $this->expires_at->isFuture() && $this->attempts < config('portal.sms.max_attempts');
    }
}
