<?php

namespace App\Http\Controllers\Federation;

use App\Federation\Federation;
use App\Http\Controllers\Controller;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Http\JsonResponse;

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
}
