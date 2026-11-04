<?php

namespace App\Nas;

use InvalidArgumentException;

final class NasDrivers
{
    /** @var array<string, class-string<NasDriver>> */
    public const DRIVERS = [
        'mikrotik_ros7' => MikrotikRos7Driver::class,
        // Experimental, controller API instead of RADIUS (decision 2026-11-04, v0.27.0).
        UnifiDriver::KEY => UnifiDriver::class,
        // Experimental, RADIUS with a per-site mapping and vendor presets (decision 2026-11-04, v0.28.0).
        GenericRadiusDriver::KEY => GenericRadiusDriver::class,
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
