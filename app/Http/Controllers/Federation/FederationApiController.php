<?php

namespace App\Http\Controllers\Federation;

use App\Federation\Federation;
use App\Federation\Pairing;
use App\Federation\PairingException;
use App\Federation\SigningKey;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateFederationPeer;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Federation API endpoints called by other instances (CONCEPT.md §10.2).
 */
class FederationApiController extends Controller
{
    /** Heartbeat: identity, clock and protocol of this instance. */
    public function status(LocalInstance $instance): JsonResponse
    {
        return response()->json([
            'instance_id' => $instance->id(),
            'protocol' => Federation::FEDERATION_PROTOCOL,
            'time_ms' => Hlc::wallMs(),
        ]);
    }

    /** Pairing: register a fresh instance presenting a valid join token (CONCEPT.md §10.2). */
    public function join(Request $request, Pairing $pairing): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'protocol' => ['required', 'integer'],
            'instance' => ['required', 'array'],
            'instance.id' => ['required', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'instance.name' => ['required', 'string', 'max:100'],
            'instance.federation_url' => ['required', 'url:https', 'max:255'],
            'instance.admin_url' => ['nullable', 'url:https,http', 'max:255'],
            'instance.signing_public_key' => ['required', 'string', 'max:64'],
        ]);
        if (! SigningKey::isPublicKey($data['instance']['signing_public_key'])) {
            return response()->json(['error' => 'invalid signing key'], 422);
        }

        try {
            $response = $pairing->acceptJoin($data, (string) $request->attributes->get(AuthenticateFederationPeer::PIN));
        } catch (PairingException $e) {
            Log::warning('Federation join refused', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['error' => $e->getMessage()], $e->status);
        }
        Log::info('Federation instance joined', ['instance' => $data['instance']['id'], 'name' => $data['instance']['name']]);

        return response()->json($response);
    }
}
