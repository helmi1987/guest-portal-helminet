<?php

namespace App\Nas;

use InvalidArgumentException;

final class NasDrivers
{
    /** @var array<string, class-string<NasDriver>> */
    public const DRIVERS = [
        'mikrotik_ros7' => MikrotikRos7Driver::class,
    ];

    public static function make(string $driver): NasDriver
    {
        $class = self::DRIVERS[$driver] ?? throw new InvalidArgumentException("Unknown NAS driver [$driver].");

        return app($class);
    }

    /** @return array<string, string> driver key => label */
    public static function options(): array
    {
        return array_map(fn (string $class) => app($class)->label(), self::DRIVERS);
    }
}
