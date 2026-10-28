<?php

namespace App\Support;

final class Duration
{
    /** Compact human duration, e.g. "1 d 2 h", "45 min". */
    public static function format(?int $seconds): string
    {
        if ($seconds === null) {
            return '–';
        }
        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'min' => 60] as $unit => $size) {
            if ($seconds >= $size) {
                $parts[] = intdiv($seconds, $size).' '.$unit;
                $seconds %= $size;
            }
        }

        return $parts ? implode(' ', array_slice($parts, 0, 2)) : '< 1 min';
    }
}
