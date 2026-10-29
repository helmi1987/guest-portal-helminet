<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
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

    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Deterministic ULID-shaped id of a key: "0" + 25 Crockford base32 characters of sha256(key). */
    public static function idFor(string $key): string
    {
        $bits = '';
        foreach (str_split(hash('sha256', $key, true)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $id = '0';
        for ($i = 0; $i < 25; $i++) {
            $id .= self::CROCKFORD[bindec(substr($bits, $i * 5, 5))];
        }

        return $id;
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
