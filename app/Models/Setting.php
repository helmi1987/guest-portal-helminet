<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use App\Support\DeterministicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Federation-wide settings (replicated). The id is derived from the key, so instances that create
 * the same setting independently write the same row (last writer wins, CONCEPT.md §10.3).
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** Deterministic id of a setting key. */
    public static function idFor(string $key): string
    {
        return DeterministicId::for($key);
    }

    public function newUniqueId(): string
    {
        return self::idFor((string) $this->key);
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
