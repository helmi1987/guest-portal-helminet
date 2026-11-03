<?php

namespace App\Http\Middleware;

use App\Guest\GuestTexts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest zone only (after `locale`): the admin's guest text overrides of the guest's language replace the
 * lang file texts while this request renders (CONCEPT.md §9 "Guest texts"). One setting read per request.
 */
class ApplyGuestTexts
{
    public function handle(Request $request, Closure $next): Response
    {
        GuestTexts::apply(app()->getLocale());
        try {
            return $next($request);
        } finally {
            GuestTexts::restore();
        }
    }
}
