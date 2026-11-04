<?php

namespace App\Support;

/**
 * Admin-defined display order of a fixed set of keys (login methods, social providers; CONCEPT.md §7,
 * decision 2026-11-04). Stored orders are read leniently: unknown and duplicate entries are dropped,
 * keys missing from the stored order are appended in default order, anything that is not a list gives
 * the default order. Input from the settings form is checked strictly with isPermutation().
 */
final class DisplayOrder
{
    public const UP = 'up';

    public const DOWN = 'down';

    /**
     * @param  list<string>  $default  all known keys in default order
     * @return list<string>
     */
    public static function normalize(mixed $stored, array $default): array
    {
        if (! is_array($stored) || ! array_is_list($stored)) {
            return $default;
        }
        $order = [];
        foreach ($stored as $key) {
            if (is_string($key) && in_array($key, $default, true) && ! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        return [...$order, ...array_values(array_diff($default, $order))];
    }

    /**
     * Every known key exactly once, nothing else.
     *
     * @param  list<string>  $default
     */
    public static function isPermutation(mixed $input, array $default): bool
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) !== count($default)) {
            return false;
        }
        foreach ($input as $key) {
            if (! is_string($key)) {
                return false;
            }
        }
        $sorted = $input;
        sort($sorted);
        $known = $default;
        sort($known);

        return $sorted === $known;
    }

    /**
     * Move one key a step up or down; at the edge (or for an unknown key) the order stays as it is.
     *
     * @param  list<string>  $order
     * @return list<string>
     */
    public static function move(array $order, string $key, string $direction): array
    {
        $index = array_search($key, $order, true);
        if ($index === false) {
            return $order;
        }
        $target = $direction === self::UP ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($order)) {
            return $order;
        }
        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        return $order;
    }

    /**
     * Values of the move buttons ("<key>:up", "<key>:down") for validation.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    public static function moveValues(array $default): array
    {
        $values = [];
        foreach ($default as $key) {
            $values[] = $key.':'.self::UP;
            $values[] = $key.':'.self::DOWN;
        }

        return $values;
    }
}
