<?php

namespace App\Approval\Channels;

use InvalidArgumentException;

/**
 * Registry of notification channel drivers.
 */
final class NotificationChannels
{
    /** @var array<string, class-string<NotificationChannel>> */
    public const DRIVERS = [
        'telegram' => TelegramChannel::class,
        'gotify' => GotifyChannel::class,
        'webhook' => WebhookChannel::class,
    ];

    public static function make(string $driver): NotificationChannel
    {
        $class = self::DRIVERS[$driver] ?? throw new InvalidArgumentException("Unknown notification driver [$driver].");

        return app($class);
    }

    /** @return array<string, array{secret?: bool, required?: bool}> */
    public static function fields(string $driver): array
    {
        return isset(self::DRIVERS[$driver]) ? self::DRIVERS[$driver]::fields() : [];
    }
}
