<?php

namespace App\Http\Controllers;

use App\Models\NasClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Router certificate download for login-by=https (CONCEPT.md §6). Fetched by the RouterOS scheduler
 * of each router with its own token (header X-Portal-Token) from its NAS IP.
 */
class RouterCertController extends Controller
{
    public function __invoke(Request $request, NasClient $nas, string $file): Response
    {
        // Router token and, by default, the router's own NAS IP as source (generated fetch uses src-address).
        $token = (string) $request->header('X-Portal-Token');
        $reason = match (true) {
            $token === '' || ! hash_equals($nas->certToken(), $token) => 'invalid token',
            config('portal.router_cert.check_source_ip') && $request->ip() !== $nas->nas_ip => 'source IP is not the NAS IP',
            default => null,
        };
        if ($reason !== null) {
            Log::warning('Router certificate download denied', ['nas' => $nas->id, 'ip' => $request->ip(), 'reason' => $reason]);
            abort(403);
        }

        $paths = config('portal.router_cert');
        $cert = is_readable($paths['cert']) ? (string) file_get_contents($paths['cert']) : '';
        if ($cert === '' || ($fingerprint = @openssl_x509_fingerprint($cert, 'sha256')) === false) {
            Log::warning('Router certificate requested but not available', ['nas' => $nas->id, 'path' => $paths['cert']]);
            abort(404);
        }

        $headers = ['Content-Type' => 'application/x-pem-file', 'Cache-Control' => 'no-store'];
        if ($file === 'router.key') {
            Log::info('Router certificate key downloaded', ['nas' => $nas->id, 'ip' => $request->ip()]);
        }

        return match ($file) {
            // Leaf certificate fingerprint; the router compares it with its installed certificate.
            'fingerprint' => response($fingerprint, 200, ['Content-Type' => 'text/plain'] + $headers),
            'router.crt' => response($cert, 200, $headers),
            'router.key' => is_readable($paths['key'])
                ? response((string) file_get_contents($paths['key']), 200, $headers)
                : abort(404),
        };
    }
}
