<?php

namespace App\Http\Controllers\Concerns;

use App\Models\NasClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Downloads requested by a router itself (router certificate, hotspot pages): per-router token in the
 * header X-Portal-Token and, by default, the router's NAS IP as source (the generated fetch commands
 * use src-address). Only PORTAL_ROUTER_IP_CHECK=false switches the IP check off (portal.router_cert.check_source_ip).
 */
trait AuthorizesRouter
{
    /** Resolve the router from the route parameter; unknown ids are denied like a wrong token (403). */
    protected function authorizeRouter(Request $request, string $id, string $what): NasClient
    {
        $nas = preg_match('/^([0-9A-Za-z]{26}|\d{1,18})$/', $id) ? NasClient::findServed($id) : null;
        $token = (string) $request->header('X-Portal-Token');
        $reason = match (true) {
            $nas === null => 'unknown router',
            $token === '' || ! hash_equals($nas->certToken(), $token) => 'invalid token',
            config('portal.router_cert.check_source_ip') && $request->ip() !== $nas->nas_ip => 'source IP is not the NAS IP',
            default => null,
        };
        if ($reason !== null) {
            Log::warning("$what denied", ['nas' => $nas?->id ?? mb_substr($id, 0, 20), 'ip' => $request->ip(), 'reason' => $reason]);
            abort(403);
        }

        return $nas;
    }
}
