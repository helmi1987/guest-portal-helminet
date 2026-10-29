<?php

use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\GrantController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SetupController;
use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Admin\TermsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApprovalLinkController;
use App\Http\Controllers\Guest\LocaleController;
use App\Http\Controllers\Guest\PortalController;
use App\Livewire\Admin\Login;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
    Route::post('/portal/approval', [PortalController::class, 'requestApproval'])->name('guest.approval.request')
        ->middleware('throttle:5,1');
    Route::get('/portal/approval', [PortalController::class, 'approval'])->name('guest.approval');
    Route::post('/portal/sms', [PortalController::class, 'requestSms'])->name('guest.sms.request')
        ->middleware('throttle:5,1');
    Route::get('/portal/sms', [PortalController::class, 'sms'])->name('guest.sms');
    Route::post('/portal/sms/verify', [PortalController::class, 'verifySms'])->name('guest.sms.verify')
        ->middleware('throttle:20,1');
    Route::post('/portal/sms/resend', [PortalController::class, 'resendSms'])->name('guest.sms.resend')
        ->middleware('throttle:5,1');
    Route::get('/status/{grant}/{token}', [PortalController::class, 'status'])->name('guest.status')
        ->whereNumber('token')->middleware('signed:relative');
});

// Signed approve/deny links (Gotify, webhook): no login and no session, so opening a link does not
// replace an admin session cookie; the signature authorizes GET (show) and POST (decide).
Route::middleware(['zone:admin', 'signed:relative', 'throttle:30,1'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->prefix('admin/approval-link')->group(function () {
        Route::get('/{approval}', [ApprovalLinkController::class, 'show'])->name('approval.link');
        Route::post('/{approval}', [ApprovalLinkController::class, 'decide']);
    });

Route::middleware(['zone:admin', 'locale'])->prefix('admin')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', Login::class)->name('login');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', LogoutController::class)->name('logout');

        // Own profile: reachable without 2FA so that "require 2FA" users can enrol.
        Route::middleware('role:admin,manager')->prefix('profile')->name('admin.')->group(function () {
            Route::get('/', [ProfileController::class, 'show'])->name('profile');
            Route::post('/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
            Route::post('/two-factor', [ProfileController::class, 'beginTwoFactor'])->name('profile.two-factor');
            Route::post('/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.two-factor.confirm')
                ->middleware('throttle:10,1');
            Route::post('/two-factor/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])
                ->name('profile.two-factor.recovery')->middleware('throttle:10,1');
            Route::delete('/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable')
                ->middleware('throttle:10,1');
        });

        Route::middleware('two_factor')->group(function () {
            // Admin + manager (CONCEPT.md §9): codes/grants, approvals.
            Route::middleware('role:admin,manager')->name('admin.')->group(function () {
                Route::view('/', 'admin.dashboard')->name('dashboard');
                Route::get('/grants/print', [GrantController::class, 'print'])->name('grants.print');
                Route::resource('grants', GrantController::class)->only(['index', 'create', 'store', 'edit', 'update']);
                Route::post('/grants/{grant}/extend', [GrantController::class, 'extend'])->name('grants.extend');
                Route::post('/grants/{grant}/expiry', [GrantController::class, 'expiry'])->name('grants.expiry');
                Route::post('/grants/{grant}/revoke', [GrantController::class, 'revoke'])->name('grants.revoke');
                Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
                Route::post('/approvals/{approval}', [ApprovalController::class, 'decide'])->name('approvals.decide');
            });

            // Admin only: settings, channels, terms, sites/routers/setup, users, audit log.
            Route::middleware('role:admin')->name('admin.')->group(function () {
                Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
                Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
                Route::post('/settings/sms', [SettingsController::class, 'updateSms'])->name('settings.sms');
                Route::post('/settings/security', [SettingsController::class, 'updateSecurity'])->name('settings.security');
                Route::post('/channels/{channel}/test', [SettingsController::class, 'testChannel'])->name('channels.test')
                    ->middleware('throttle:5,1');
                Route::get('/channels/create', [SettingsController::class, 'createChannel'])->name('channels.create');
                Route::post('/channels', [SettingsController::class, 'storeChannel'])->name('channels.store');
                Route::get('/channels/{channel}', [SettingsController::class, 'editChannel'])->name('channels.edit');
                Route::put('/channels/{channel}', [SettingsController::class, 'updateChannel'])->name('channels.update');
                Route::delete('/channels/{channel}', [SettingsController::class, 'destroyChannel'])->name('channels.destroy');
                Route::get('/terms', [TermsController::class, 'index'])->name('terms');
                Route::post('/terms/{locale}', [TermsController::class, 'update'])->name('terms.update');

                Route::resource('sites', SiteController::class)->except(['show']);
                Route::get('/sites/{site}/login.html', [SiteController::class, 'loginPage'])->name('sites.login-page');
                Route::get('/sites/{site}/setup', [SetupController::class, 'show'])->name('sites.setup');
                Route::post('/sites/{site}/setup', [SetupController::class, 'updateNetwork'])->name('sites.setup.network');
                Route::post('/sites/{site}/setup/container', [SetupController::class, 'updateContainer'])->name('sites.setup.container');
                Route::scopeBindings()->prefix('/sites/{site}/routers')->name('sites.routers.')->group(function () {
                    Route::post('/', [SiteController::class, 'storeRouter'])->name('store');
                    Route::delete('/{router}', [SiteController::class, 'destroyRouter'])->name('destroy');
                    Route::post('/{router}/secret', [SiteController::class, 'regenerateSecret'])->name('secret');
                    Route::get('/{router}/config', [SiteController::class, 'routerConfig'])->name('config');
                });

                Route::resource('users', UserController::class)->except(['show']);
                Route::post('/users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
                Route::get('/audit', [AuditController::class, 'index'])->name('audit');
            });
        });
    });
});

// Internal routes: routes/internal.php (no session/CSRF, see bootstrap/app.php).
