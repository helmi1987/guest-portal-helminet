<?php

use App\Http\Middleware\DetectZone;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\EnsureZone;
use App\Http\Middleware\InternalApiAuth;
use App\Http\Middleware\SetLocale;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
            Route::middleware(['zone:guest', SubstituteBindings::class, 'throttle:30,1'])
                ->group(base_path('routes/router.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(DetectZone::class);
        $middleware->alias([
            'zone' => EnsureZone::class,
            'role' => EnsureRole::class,
            'two_factor' => EnsureTwoFactor::class,
            'locale' => SetLocale::class,
        ]);
        // The zone check must run before auth redirects, otherwise /admin on the guest port redirects.
        $middleware->prependToPriorityList(EncryptCookies::class, EnsureZone::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
        $middleware->trustProxies(at: env('TRUSTED_PROXIES'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('internal/*') || $request->expectsJson(),
        );
    })->create();
