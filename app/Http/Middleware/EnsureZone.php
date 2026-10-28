<?php

namespace App\Http\Middleware;

use App\Support\Zone;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `zone:<name>`: the route exists only on that listener, 404 elsewhere.
 */
class EnsureZone
{
    public function handle(Request $request, Closure $next, string $zone): Response
    {
        $current = Zone::of($request);

        abort_unless($current === $zone || $current === Zone::ALL, 404);

        return $next($request);
    }
}
