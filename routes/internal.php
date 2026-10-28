<?php

use App\Http\Controllers\Internal\RadiusController;
use Illuminate\Support\Facades\Route;

// Loopback-only internal API (zone "internal"), prefix /internal, basic auth with INTERNAL_API_TOKEN.
Route::post('/radius/authorize', [RadiusController::class, 'authorizeAccess'])->name('internal.radius.authorize');
Route::post('/radius/accounting', [RadiusController::class, 'accounting'])->name('internal.radius.accounting');
