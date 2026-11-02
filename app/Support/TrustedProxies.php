<?php

namespace App\Support;

/**
 * Value of TRUSTED_PROXIES (CONCEPT.md §12): only specific proxy addresses or networks. A value that
 * trusts every client (`*`, `**` or a /0 network such as `0.0.0.0/0`, `::/0`) would let any guest
 * choose its IP address by X-Forwarded-For and so defeat every per-IP limit; it is ignored (no trusted
 * proxies) and `portal:init` logs an error at container start.
 */
final class TrustedProxies
{
    /** @return string|null comma-separated proxies for Middleware::trustProxies(), null: none */
    public static function resolve(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || self::trustsAll($value)) {
            return null;
        }

        return $value;
    }

    public static function trustsAll(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '*' || $entry === '**' || preg_match('#/0+$#', $entry)) {
                return true;
            }
        }

        return false;
    }
}
