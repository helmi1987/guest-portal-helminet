<?php

use App\Http\Middleware\ApplyGuestTexts;
use App\Http\Middleware\AuthenticateFederationPeer;
use App\Http\Middleware\BounceOidcCallback;
use App\Http\Middleware\DetectZone;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\EnsureZone;
use App\Http\Middleware\InternalApiAuth;
use App\Http\Middleware\SetLocale;
use App\Support\TrustedProxies;
use App\Support\Zone;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['zone:internal', InternalApiAuth::class])
                ->prefix('internal')
                ->group(base_path('routes/internal.php'));
            Route::middleware(['zone:guest', SubstituteBindings::class, 'throttle:30,1,router'])
                ->group(base_path('routes/router.php'));
            Route::middleware(['zone:guest,admin', 'throttle:300,1,branding'])
                ->group(base_path('routes/branding.php'));
            // Federation API between instances (port 9443, mTLS): no session, cookies or CSRF.
            Route::middleware(['zone:federation', 'throttle:600,1,federation'])
                ->prefix('federation')
                ->group(base_path('routes/federation.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(DetectZone::class);
        // Wi-Fi passphrases and directory passwords may start or end with spaces (the guest's "password" is a default exception).
        $middleware->trimStrings(except: ['wifi_password', 'api_password', 'directory.bind_password']);
        $middleware->alias([
            'zone' => EnsureZone::class,
            'role' => EnsureRole::class,
            'two_factor' => EnsureTwoFactor::class,
            'locale' => SetLocale::class,
            'guest.texts' => ApplyGuestTexts::class,
            'federation.peer' => AuthenticateFederationPeer::class,
            'oidc.bounce' => BounceOidcCallback::class,
        ]);
        // The zone check must run before auth redirects, otherwise /admin on the guest port redirects.
        $middleware->prependToPriorityList(EncryptCookies::class, EnsureZone::class);
        // The OIDC callback bounce must answer before a session (and a new session cookie) is started.
        $middleware->prependToPriorityList(StartSession::class, BounceOidcCallback::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
        // Specific proxies only: a trust-all value is ignored (TrustedProxies, error logged by portal:init).
        $middleware->trustProxies(at: TrustedProxies::resolve(env('TRUSTED_PROXIES')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Guest Wi-Fi password, router API credentials and the directory service account password: never
        // flashed to the session as old input (the guest's "password" is a default exception).
        $exceptions->dontFlash(['wifi_password', 'api_user', 'api_password', 'directory.bind_password']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('internal/*', 'federation/*') || $request->expectsJson()
                || in_array(Zone::of($request), [Zone::INTERNAL, Zone::FEDERATION], true),
        );
    })->create();
