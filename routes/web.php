<?php

use App\Http\Controllers\Admin\GrantController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Admin\TermsController;
use App\Http\Controllers\Guest\LocaleController;
use App\Http\Controllers\Guest\PortalController;
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
    Route::get('/portal', [PortalController::class, 'show'])->name('guest.portal');
    Route::post('/portal/code', [PortalController::class, 'redeem'])->name('guest.redeem')
        ->middleware('throttle:20,1');
    Route::get('/status/{grant}/{token}', [PortalController::class, 'status'])->name('guest.status')
        ->whereNumber('token')->middleware('signed:relative');
});

Route::middleware(['zone:admin', 'locale'])->prefix('admin')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', Login::class)->name('login');
    });

    Route::middleware('auth')->group(function () {
        Route::view('/', 'admin.dashboard')->name('admin.dashboard');
        Route::post('/logout', LogoutController::class)->name('logout');

        Route::middleware('role:admin,manager')->name('admin.')->group(function () {
            Route::get('/grants/print', [GrantController::class, 'print'])->name('grants.print');
            Route::resource('grants', GrantController::class)->only(['index', 'create', 'store', 'edit', 'update']);
            Route::post('/grants/{grant}/extend', [GrantController::class, 'extend'])->name('grants.extend');
            Route::post('/grants/{grant}/expiry', [GrantController::class, 'expiry'])->name('grants.expiry');
            Route::post('/grants/{grant}/revoke', [GrantController::class, 'revoke'])->name('grants.revoke');
        });

        Route::middleware('role:admin')->group(function () {
            Route::view('/settings', 'admin.settings')->name('admin.settings');
            Route::get('/terms', [TermsController::class, 'index'])->name('admin.terms');
            Route::post('/terms/{locale}', [TermsController::class, 'update'])->name('admin.terms.update');

            Route::name('admin.')->group(function () {
                Route::resource('sites', SiteController::class)->except(['show']);
                Route::get('/sites/{site}/login.html', [SiteController::class, 'loginPage'])->name('sites.login-page');
                Route::scopeBindings()->prefix('/sites/{site}/routers')->name('sites.routers.')->group(function () {
                    Route::post('/', [SiteController::class, 'storeRouter'])->name('store');
                    Route::delete('/{router}', [SiteController::class, 'destroyRouter'])->name('destroy');
                    Route::post('/{router}/secret', [SiteController::class, 'regenerateSecret'])->name('secret');
                    Route::get('/{router}/config', [SiteController::class, 'routerConfig'])->name('config');
                });
            });
        });
    });
});

// Internal routes: routes/internal.php (no session/CSRF, see bootstrap/app.php).
