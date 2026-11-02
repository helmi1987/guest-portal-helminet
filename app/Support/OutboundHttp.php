<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for admin-configured endpoints (approval channels, SMS gateways; CONCEPT.md §12):
 * redirects are not followed and count as an error, so a secret header or signed body never reaches
 * another URL; short connect and total timeouts. Private addresses stay allowed (Gotify and similar
 * services often run in the operator's LAN).
 */
final class OutboundHttp
{
    public static function client(int $timeout = 10): PendingRequest
    {
        return Http::withoutRedirecting()->connectTimeout(5)->timeout($timeout);
    }

    /** Throw on a redirect answer like on a 4xx/5xx (Response::throw() only covers those). */
    public static function rejectRedirect(Response $response): Response
    {
        return $response->throwIfStatus(fn (int $status) => $status >= 300 && $status < 400);
    }
}
