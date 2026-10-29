<?php

namespace App\Support;

/**
 * ULID-shaped id derived from a name ("0" + 25 Crockford base32 characters of sha256(name)), for rows
 * that independent instances must create under the same id (CONCEPT.md §10.3).
 */
final class DeterministicId
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function for(string $name): string
    {
        $bits = '';
        foreach (str_split(hash('sha256', $name, true)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $id = '0';
        for ($i = 0; $i < 25; $i++) {
            $id .= self::CROCKFORD[bindec(substr($bits, $i * 5, 5))];
        }

        return $id;
    }
}
