<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Federation-wide settings (replicated).
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
