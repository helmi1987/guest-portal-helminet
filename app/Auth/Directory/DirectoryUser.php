<?php

namespace App\Auth\Directory;

use App\Auth\Social\SocialIdentity;

/**
 * A directory account whose password was verified by a bind (CONCEPT.md §7.2 E): the user name as typed,
 * names and email from the directory attributes. The DN is kept for the log of this request only.
 */
final class DirectoryUser
{
    public function __construct(
        public readonly string $username,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $email,
    ) {}

    /**
     * Names from the attributes: the first non-empty name attribute (default displayName) split into first
     * and last name ("Last, First" at the comma, otherwise at the last space); without one, givenName and
     * sn (sn also completes a one-word name). A missing first name falls back to the user name.
     *
     * @param  array<string, list<string>>  $attributes  lower-case attribute names
     * @param  list<string>  $nameAttributes
     */
    public static function fromAttributes(string $username, array $attributes, array $nameAttributes, string $emailAttribute): self
    {
        $value = fn (string $name) => SocialIdentity::clean($attributes[strtolower($name)][0] ?? '');
        [$first, $last] = ['', ''];
        foreach ($nameAttributes as $name) {
            if (($full = $value($name)) !== '') {
                [$first, $last] = self::splitName($full);
                break;
            }
        }
        if ($first === '' && $last === '') {
            [$first, $last] = [$value('givenName'), $value('sn')];
        } elseif ($last === '') {
            $last = $value('sn');
        }
        if ($first === '') {
            $first = SocialIdentity::clean($username);
        }
        $email = $emailAttribute !== '' ? SocialIdentity::cleanEmail($attributes[strtolower($emailAttribute)][0] ?? null) : null;

        return new self($username, $first, $last, $email);
    }

    /**
     * "Anna Maria Muster" → ["Anna Maria", "Muster"]; "Muster, Anna" → ["Anna", "Muster"]; "Anna" → ["Anna", ""].
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $full): array
    {
        $full = SocialIdentity::clean($full);
        if (str_contains($full, ',')) {
            [$last, $first] = array_map('trim', explode(',', $full, 2));
            if ($first !== '' && $last !== '') {
                return [SocialIdentity::clean($first), SocialIdentity::clean($last)];
            }
            $full = trim(str_replace(',', ' ', $full));
        }
        $pos = mb_strrpos($full, ' ');
        if ($pos === false) {
            return [$full, ''];
        }

        return [SocialIdentity::clean(mb_substr($full, 0, $pos)), SocialIdentity::clean(mb_substr($full, $pos + 1))];
    }
}
