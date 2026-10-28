<?php

namespace App\Support;

/**
 * Removes secrets from text before it is logged (e.g. Telegram bot tokens in request URLs).
 */
final class Redact
{
    public static function secrets(string $text): string
    {
        return preg_replace('#/bot[^/\s]+#', '/bot***', $text);
    }
}
