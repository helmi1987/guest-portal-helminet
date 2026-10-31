<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Cast `LocalEncrypted`: instance-local secret encrypted with APP_KEY, same storage format as Laravel's
 * `encrypted` cast. A value that cannot be decrypted (e.g. APP_KEY changed) reads as null with a warning
 * instead of failing the page; the value itself is never logged.
 */
class LocalEncrypted implements CastsAttributes, ComparesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            logger()->warning('Encrypted local value cannot be decrypted; enter it again.', ['model' => $model::class, 'id' => $model->getKey(), 'field' => $key]);

            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }

    /** Same plain value = not dirty (each encryption yields a new ciphertext). */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }
        try {
            return Crypt::decryptString($firstValue) === Crypt::decryptString($secondValue);
        } catch (Throwable) {
            return false;
        }
    }
}
