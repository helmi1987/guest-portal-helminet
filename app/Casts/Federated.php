<?php

namespace App\Casts;

use App\Federation\DataKey;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Cast `federated`: replicated secret encrypted with the federation data key (CONCEPT.md §10.2).
 * `Federated::class` for strings, `Federated::class.':array'` for JSON arrays.
 */
class Federated implements CastsAttributes, ComparesCastableAttributes
{
    public function __construct(private ?string $type = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $plain = app(DataKey::class)->decrypt($value);

        return $this->type === 'array' ? json_decode($plain, true) : $plain;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(DataKey::class)->encrypt($this->type === 'array' ? json_encode($value) : (string) $value);
    }

    /**
     * Same plain value under the same key = not dirty (each encryption yields a new ciphertext); a
     * value re-encrypted with another key is dirty, so a rotation rewrites it.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }
        try {
            $keys = app(DataKey::class);

            return DataKey::keyIdOf($firstValue) === DataKey::keyIdOf($secondValue)
                && $keys->decrypt($firstValue) === $keys->decrypt($secondValue);
        } catch (Throwable) {
            return false;
        }
    }
}
