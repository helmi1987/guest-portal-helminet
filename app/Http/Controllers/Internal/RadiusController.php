<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoints called by FreeRADIUS rlm_rest (CONCEPT.md §5). M1 stub: every request is rejected.
 * rlm_rest maps HTTP 401 to "reject"; 2xx with attributes will be used for Access-Accept (M2).
 */
class RadiusController extends Controller
{
    public function authorizeAccess(Request $request): JsonResponse
    {
        return response()->json([
            'reply:Reply-Message' => 'Access denied',
        ], 401);
    }

    public function accounting(Request $request): Response
    {
        return response()->noContent();
    }
}
