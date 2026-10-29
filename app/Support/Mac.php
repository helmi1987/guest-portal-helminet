<?php

namespace App\Support;

final class Mac
{
    /** Normalise any common MAC notation to AA:BB:CC:DD:EE:FF; null if invalid. */
    public static function normalize(?string $mac): ?string
    {
        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    /**
     * Strict variant for values that must be a MAC and nothing else (e.g. a RADIUS User-Name):
     * AA:BB:CC:DD:EE:FF, AA-BB-CC-DD-EE-FF or AABBCCDDEEFF (any case); null otherwise.
     */
    public static function strict(?string $value): ?string
    {
        return preg_match('/^[0-9A-Fa-f]{2}([:-]?)[0-9A-Fa-f]{2}(\1[0-9A-Fa-f]{2}){4}\z/', (string) $value) === 1
            ? self::normalize($value) : null;
    }
}
