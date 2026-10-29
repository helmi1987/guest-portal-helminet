<?php

namespace App\Support;

/**
 * Removes secrets and personal data from text before it is logged (e.g. Telegram bot tokens in
 * request URLs, phone numbers in provider errors).
 */
final class Redact
{
    /** @param  list<?string>  $known  configured secrets (e.g. API keys) to mask wherever they appear */
    public static function secrets(string $text, array $known = []): string
    {
        foreach (array_filter($known, fn ($secret) => is_string($secret) && strlen($secret) >= 4) as $secret) {
            $text = str_replace($secret, '***', $text);
        }
        $text = preg_replace('#/bot[^/\s]+#', '/bot***', $text);

        // Phone-like digit runs (9-15 digits, optional +).
        return preg_replace_callback('/(?<![\w+])\+?\d{9,15}(?!\w)/', fn ($m) => self::phone($m[0]), $text);
    }

    /** Partially masked phone number for logs and the manager role, e.g. +4179*****67. */
    public static function phone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '';
        }
        $keep = str_starts_with($phone, '+') ? 5 : 4;

        return mb_substr($phone, 0, $keep).str_repeat('*', max(0, mb_strlen($phone) - $keep - 2)).mb_substr($phone, -2);
    }
}
