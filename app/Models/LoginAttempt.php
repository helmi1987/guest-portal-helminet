<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['mac', 'ip', 'site_id', 'method', 'success', 'created_at'])]
class LoginAttempt extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['success' => 'boolean', 'created_at' => 'datetime'];
    }
}
