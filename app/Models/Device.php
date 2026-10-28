<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MAC binding of a grant. Active while unbound_at is null.
 */
#[Fillable(['grant_id', 'guest_id', 'mac', 'bound_at', 'unbound_at'])]
class Device extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['bound_at' => 'datetime', 'unbound_at' => 'datetime'];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
