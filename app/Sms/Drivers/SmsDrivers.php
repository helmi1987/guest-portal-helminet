<?php

namespace App\Sms\Drivers;

use InvalidArgumentException;

/**
 * Registry of SMS drivers.
 */
final class SmsDrivers
{
    /** @var array<string, class-string<SmsDriver>> */
    public const DRIVERS = [
        'clickatell' => ClickatellDriver::class,
        'sms_webhook' => WebhookSmsDriver::class,
    ];

    public static function make(string $driver): SmsDriver
    {
        $class = self::DRIVERS[$driver] ?? throw new InvalidArgumentException("Unknown SMS driver [$driver].");

        return app($class);
    }
}
