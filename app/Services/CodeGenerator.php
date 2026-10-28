<?php

namespace App\Services;

use App\Models\Grant;
use RuntimeException;

/**
 * Random 6-digit codes, unique among grants that are still usable; trivial codes excluded.
 */
class CodeGenerator
{
    public function generate(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (! self::isTrivial($code) && ! $this->inUse($code)) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique code.');
    }

    public static function isTrivial(string $code): bool
    {
        $digits = array_map('intval', str_split($code));
        $steps = array_unique(array_map(fn ($a, $b) => $b - $a, array_slice($digits, 0, -1), array_slice($digits, 1)));

        // Same digit, ascending/descending runs, or repeated pairs/triples (121212, 123123).
        return count($steps) === 1 && in_array(reset($steps), [-1, 0, 1], true)
            || substr($code, 0, 2) === substr($code, 2, 2) && substr($code, 2, 2) === substr($code, 4, 2)
            || substr($code, 0, 3) === substr($code, 3, 3);
    }

    private function inUse(string $code): bool
    {
        return Grant::query()->where('code', $code)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
