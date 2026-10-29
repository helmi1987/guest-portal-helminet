<?php

namespace App\Http\Middleware;

use App\Federation\PeerCertificate;
use App\Models\Instance;
use App\Support\LocalInstance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Federation API authentication (CONCEPT.md §10.2): nginx passes the client certificate of the mTLS
 * handshake (FastCGI parameter PORTAL_CLIENT_CERT, URL-encoded PEM; not settable by clients). Its
 * public key pin must match a registered, not removed instance. `federation.peer:join` only requires a
 * certificate (pairing: the joining instance is not registered yet, the join token authorizes).
 */
class AuthenticateFederationPeer
{
    public const PEER = 'federation.peer';

    public const PIN = 'federation.pin';

    public function __construct(private LocalInstance $instance) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $pin = PeerCertificate::pin(rawurldecode((string) $request->server('PORTAL_CLIENT_CERT')));
        if ($pin === null) {
            return response()->json(['error' => 'client certificate required'], 403);
        }
        $request->attributes->set(self::PIN, $pin);

        if ($mode !== 'join') {
            $peer = Instance::query()->where('cert_fingerprint', $pin)->whereKeyNot($this->instance->id())->first();
            if ($peer === null) {
                return response()->json(['error' => 'unknown peer'], 403);
            }
            $request->attributes->set(self::PEER, $peer);
        }

        return $next($request);
    }
}
