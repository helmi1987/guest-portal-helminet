<?php

namespace App\Auth\Social;

/**
 * Identity reported by a social login provider after a verified callback: provider, the provider's
 * subject (user ID), names as far as known (empty when not reported) and the email (CONCEPT.md §7.2 D).
 */
final class SocialIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $email,
    ) {}

    /**
     * Name part as stored: trimmed, single spaces, at most 100 characters (like the login forms).
     */
    public static function clean(mixed $value): string
    {
        $value = is_string($value) || is_int($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) $value)) : '';

        return mb_substr($value, 0, 100);
    }

    /** Email as stored: a valid address of at most 191 characters, else null. */
    public static function cleanEmail(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && mb_strlen($value) <= 191 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }
}
