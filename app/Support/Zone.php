<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Listener a request arrived on. nginx passes it as FastCGI parameter PORTAL_ZONE;
 * clients cannot set it (it is not an HTTP header).
 */
final class Zone
{
    public const GUEST = 'guest';

    public const ADMIN = 'admin';

    public const INTERNAL = 'internal';

    /** Federation API between instances (port 9443, mTLS). */
    public const FEDERATION = 'federation';

    public const ALL = 'all';

    public static function of(Request $request): ?string
    {
        $zone = $request->server('PORTAL_ZONE') ?: config('portal.default_zone');

        // "all" disables the zone check; never honour it in production.
        if ($zone === self::ALL && app()->isProduction()) {
            return null;
        }

        return in_array($zone, [self::GUEST, self::ADMIN, self::INTERNAL, self::FEDERATION, self::ALL], true) ? $zone : null;
    }
}
