<?php

namespace App\Support;

final class Bytes
{
    /** Compact byte count, e.g. "12.3 MB" (decimal units). */
    public static function format(int|float|null $bytes): string
    {
        $bytes = (float) $bytes;
        foreach (['GB' => 1e9, 'MB' => 1e6, 'kB' => 1e3] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1).' '.$unit;
            }
        }

        return (int) $bytes.' B';
    }

    /** Byte count in binary units for guests, e.g. "12.3 MiB" (as RouterOS shows usage). */
    public static function binary(int|float|null $bytes): string
    {
        $bytes = (float) $bytes;
        foreach (['GiB' => 1024 ** 3, 'MiB' => 1024 ** 2, 'KiB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1).' '.$unit;
            }
        }

        return (int) $bytes.' B';
    }
}
