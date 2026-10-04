<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceRoutePermission;
use App\Http\Middleware\RefuseDuringWarOfEmperium;
use App\Http\Middleware\ResolveServerGroup;
use App\Services\Notifications\DiscordWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
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

        /*
         * Announce unhandled exceptions to Discord, as FluxCP's
         * DiscordSendOnErrorException did.
         *
         * Off unless an operator turns it on, which is the opposite of the
         * legacy default, and the reason is in config/panel.php: an exception
         * message is where a database credential or a filesystem path ends up,
         * and a chat channel is read by more people than a log file is. The
         * message is sent as the legacy sent it, because an exception with its
         * message removed is not worth announcing -- the choice is whether to
         * announce at all.
         *
         * HTTP exceptions are skipped. A 404 is not an error worth a chat
         * message, and announcing every one of them would make the channel
         * useless on the first crawler.
         */
        $exceptions->report(function (Throwable $exception): void {
            if ($exception instanceof HttpExceptionInterface) {
                return;
            }

            app(DiscordWebhook::class)->notify('exception', sprintf(
                'Unhandled %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));
        });
    })->create();
