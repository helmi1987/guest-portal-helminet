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

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->first()?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $setting = static::query()->withTrashed()->firstOrNew(['key' => $key]);
        $setting->value = $value;
        $setting->deleted_at = null;
        $setting->save();
    }
}
