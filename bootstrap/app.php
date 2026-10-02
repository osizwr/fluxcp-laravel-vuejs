<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceRoutePermission;
use App\Http\Middleware\RefuseDuringWarOfEmperium;
use App\Http\Middleware\ResolveServerGroup;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /*
             * The API is registered inside the `web` middleware group rather
             * than the stateless `api` one, because the Vue client is a
             * first-party SPA served from this same origin and authenticates
             * with the session cookie. That gives it encrypted cookies, the
             * session, and CSRF verification, which is exactly the set of
             * protections the legacy panel relied on.
             *
             * Laravel Sanctum's SPA mode was tried first and removed: it
             * attaches the session only when a request carries a Referer or
             * Origin header, so a request without one silently loses its
             * session. Nothing else in the application needed Sanctum, and an
             * unused dependency in the authentication path is worse than none.
             * Token authentication can be added if and when a genuine
             * third-party consumer exists.
             *
             * See docs/MIGRATION_DECISIONS.md (D11).
             */
            Route::middleware(['web', ResolveServerGroup::class])
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => EnforceRoutePermission::class,
            'not-during-woe' => RefuseDuringWarOfEmperium::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
