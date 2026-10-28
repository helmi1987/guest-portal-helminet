<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP basic auth for the internal API; the password is the shared INTERNAL_API_TOKEN.
 */
class InternalApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('portal.internal_api_token');
        $given = (string) $request->getPassword();

        if ($token === '' || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
