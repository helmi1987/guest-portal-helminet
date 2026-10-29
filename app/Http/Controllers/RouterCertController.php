<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Router certificate download for login-by=https (CONCEPT.md §6). Fetched by the RouterOS scheduler
 * of each router with its own token (header X-Portal-Token) from its NAS IP.
 */
class RouterCertController extends Controller
{
    use AuthorizesRouter;

    public function __invoke(Request $request, string $nas, string $file): Response
    {
        $nas = $this->authorizeRouter($request, $nas, 'Router certificate download');

        $paths = config('portal.router_cert');
        $cert = is_readable($paths['cert']) ? (string) file_get_contents($paths['cert']) : '';
        if ($cert === '' || ($fingerprint = @openssl_x509_fingerprint($cert, 'sha256')) === false) {
            Log::warning('Router certificate requested but not available', ['nas' => $nas->id, 'path' => $paths['cert']]);
            abort(404);
        }

        $headers = ['Content-Type' => 'application/x-pem-file', 'Cache-Control' => 'no-store'];
        if ($file === 'router.crt') {
            $nas->markSeen('cert_fetched_at');
        }
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
