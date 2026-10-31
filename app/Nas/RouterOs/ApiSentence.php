<?php

namespace App\Nas\RouterOs;

/**
 * RouterOS API wire format: a sentence is a list of words, each prefixed with its length (1-5 bytes),
 * terminated by an empty word. Only what the read-only client needs.
 */
final class ApiSentence
{
    /** @param list<string> $words */
    public static function encode(array $words): string
    {
        $out = '';
        foreach ($words as $word) {
            $out .= self::length(strlen($word)).$word;
        }

        return $out."\x00";
    }

    public static function length(int $n): string
    {
        return match (true) {
            $n < 0x80 => chr($n),
            $n < 0x4000 => pack('n', $n | 0x8000),
            $n < 0x200000 => substr(pack('N', $n | 0xC00000), 1),
            $n < 0x10000000 => pack('N', $n | 0xE0000000),
            default => "\xF0".pack('N', $n),
        };
    }

    /**
     * Read one sentence (list of words) from the connection. $maxWord caps one word, $budget (by
     * reference, optional) is the remaining byte budget of the whole response: it is decreased by the
     * words and length prefixes read (an empty sentence costs 1) and exceeding it throws.
     *
     * @return list<string>
     *
     * @throws RouterOsException
     */
    public static function read(ApiConnection $connection, int $maxWord = 65536, ?int &$budget = null): array
    {
        $words = [];
        while (true) {
            $n = self::readLength($connection);
            if ($budget !== null && ($budget -= max(1, $n)) < 0) {
                throw new RouterOsException('protocol', 'answer too large');
            }
            if ($n === 0) {
                return $words;
            }
            if ($n > $maxWord) {
                throw new RouterOsException('protocol', 'word too long');
            }
            $words[] = $connection->read($n);
            if (count($words) > 10000) {
                throw new RouterOsException('protocol', 'sentence too long');
            }
        }
    }

    private static function readLength(ApiConnection $c): int
    {
        $b = ord($c->read(1));

        return match (true) {
            ($b & 0x80) === 0 => $b,
            ($b & 0xC0) === 0x80 => (($b & 0x3F) << 8) | ord($c->read(1)),
            ($b & 0xE0) === 0xC0 => (($b & 0x1F) << 16) | unpack('n', $c->read(2))[1],
            ($b & 0xF0) === 0xE0 => (($b & 0x0F) << 24) | (unpack('N', "\x00".$c->read(3))[1]),
            $b === 0xF0 => unpack('N', $c->read(4))[1],
            default => throw new RouterOsException('protocol', 'invalid length prefix'),
        };
    }
}
