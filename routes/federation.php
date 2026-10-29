<?php

use App\Http\Controllers\Federation\FederationApiController;
use Illuminate\Support\Facades\Route;

// Federation API (zone "federation", prefix /federation, CONCEPT.md §10.2). Peers authenticate with
// their pinned client certificate (mTLS).
Route::middleware('federation.peer')->group(function () {
    Route::get('/status', [FederationApiController::class, 'status'])->name('federation.status');
});
