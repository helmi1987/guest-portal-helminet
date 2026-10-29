<?php

namespace App\Http\Middleware;

use App\Support\Zone;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `zone:<name>[,<name>...]`: the route exists only on these listeners, 404 elsewhere.
 */
class EnsureZone
{
    public function handle(Request $request, Closure $next, string ...$zones): Response
    {
        $current = Zone::of($request);

        abort_unless(in_array($current, $zones, true) || $current === Zone::ALL, 404);

        return $next($request);
    }
}
