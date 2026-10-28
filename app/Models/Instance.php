<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'federation_url', 'cert_fingerprint'])]
class Instance extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'clock_skew_ms' => 'integer',
        ];
    }
}
