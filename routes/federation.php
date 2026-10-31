<?php

use App\Http\Controllers\Federation\FederationApiController;
use Illuminate\Support\Facades\Route;

// Federation API (zone "federation", prefix /federation, CONCEPT.md §10.2). Peers authenticate with
// their pinned client certificate (mTLS).
Route::middleware('federation.peer')->group(function () {
    Route::get('/status', [FederationApiController::class, 'status'])->name('federation.status');
    Route::get('/events', [FederationApiController::class, 'events'])->name('federation.events');
    Route::post('/events', [FederationApiController::class, 'receive'])->name('federation.receive');
    Route::get('/sessions', [FederationApiController::class, 'sessions'])->name('federation.sessions');
    Route::post('/sessions/{session}/disconnect', [FederationApiController::class, 'disconnect'])->name('federation.sessions.disconnect')
        ->where('session', '[0-9A-Za-z]{26}');
    Route::get('/branding/{file}', [FederationApiController::class, 'branding'])->name('federation.branding')
        ->where('file', '[a-f0-9]{64}\.(png|jpg|webp|svg|ico)');
    Route::post('/approvals/{approval}/decide', [FederationApiController::class, 'decideApproval'])->name('federation.approvals.decide')
        ->where('approval', '[0-9A-Za-z]{26}');
});

// Pairing: the joining instance is not registered yet; the one-time join token authorizes, its
// certificate (pin) is taken from the TLS handshake.
Route::post('/join', [FederationApiController::class, 'join'])->name('federation.join')
    ->middleware(['federation.peer:join', 'throttle:10,1,federation-pairing']);

// Re-pairing (new hub after a takeover): the instance is known by its pin; a join token authorizes.
Route::post('/repair', [FederationApiController::class, 'repair'])->name('federation.repair')
    ->middleware(['federation.peer:repair', 'throttle:10,1,federation-pairing']);
