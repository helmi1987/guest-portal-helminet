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
}
