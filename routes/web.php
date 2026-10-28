<?php

use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Guest\LocaleController;
use App\Livewire\Admin\Login;
use Illuminate\Support\Facades\Route;

/*
| Routes are split by listener ("zone", set by nginx per port):
|   guest    -> guest port (443)   /
|   admin    -> admin port (8443)  /admin/...
|   internal -> 127.0.0.1:8080     /internal/...
| A route answers 404 on every other port.
*/

Route::middleware(['zone:guest', 'locale'])->group(function () {
    Route::view('/', 'guest.landing')->name('guest.landing');
    Route::get('/lang/{locale}', LocaleController::class)->name('guest.locale');
});

Route::middleware(['zone:admin', 'locale'])->prefix('admin')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', Login::class)->name('login');
    });

    Route::middleware('auth')->group(function () {
        Route::view('/', 'admin.dashboard')->name('admin.dashboard');
        Route::post('/logout', LogoutController::class)->name('logout');

        Route::middleware('role:admin')->group(function () {
            Route::view('/settings', 'admin.settings')->name('admin.settings');
        });
    });
});

// Internal routes: routes/internal.php (no session/CSRF, see bootstrap/app.php).
