<?php

namespace App\Http\Middleware;

use App\Support\Zone;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware: separate session cookies per zone, since cookies are not port-specific;
 * SameSite=strict for the admin cookie.
 */
class DetectZone
{
    public function handle(Request $request, Closure $next): Response
    {
        $zone = Zone::of($request);

        if (in_array($zone, [Zone::GUEST, Zone::ADMIN], true)) {
            config(['session.cookie' => 'portal_'.$zone.'_session']);
        }
        if ($zone === Zone::ADMIN) {
            config(['session.same_site' => 'strict']);
        }

        return $next($request);
    }
}
