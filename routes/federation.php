<?php

use App\Http\Controllers\Federation\FederationApiController;
use Illuminate\Support\Facades\Route;

// Federation API (zone "federation", prefix /federation, CONCEPT.md §10.2). Peers authenticate with
// their pinned client certificate (mTLS).
Route::middleware('federation.peer')->group(function () {
    Route::get('/status', [FederationApiController::class, 'status'])->name('federation.status');
    Route::get('/events', [FederationApiController::class, 'events'])->name('federation.events');
    Route::post('/events', [FederationApiController::class, 'receive'])->name('federation.receive');
});

// Pairing: the joining instance is not registered yet; the one-time join token authorizes, its
// certificate (pin) is taken from the TLS handshake.
Route::post('/join', [FederationApiController::class, 'join'])->name('federation.join')
    ->middleware(['federation.peer:join', 'throttle:10,1']);
