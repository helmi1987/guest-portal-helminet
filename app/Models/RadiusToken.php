<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time RADIUS credentials issued after a successful portal login (local only).
 */
#[Fillable(['grant_id', 'guest_id', 'device_id', 'username', 'password_hash', 'mac', 'site_id', 'expires_at', 'used_at'])]
class RadiusToken extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    public static function hashPassword(string $password): string
    {
        // High-entropy random secret: a plain SHA-256 is sufficient.
        return hash('sha256', $password);
    }
}
