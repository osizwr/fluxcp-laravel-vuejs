<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Rathena\ServerRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route while War of Emperium is running on the selected world.
 *
 * FluxCP applied this from its global preprocess hook, with the restricted
 * routes listed per char/map pair. It exists for a real reason rather than as
 * a curiosity: the who-is-online and map-statistics pages reveal where
 * characters are, so during a siege they let guilds scout castle defences from
 * the website instead of from inside the game.
 *
 * Staff holding ViewWoeDisallowed bypass it, as before.
 */
final class RefuseDuringWarOfEmperium
{
    public function __construct(private readonly ServerRegistry $servers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return $next($request);
        }

        $pair = $this->servers->currentCharMapServer();

        if (! $pair->restrictsRouteDuringWoe($routeName)) {
            return $next($request);
        }

        if (! $pair->isWoeActive()) {
            return $next($request);
        }

        if ($request->user()?->can('ViewWoeDisallowed') === true) {
            return $next($request);
        }

        abort(
            Response::HTTP_SERVICE_UNAVAILABLE,
            'This page is unavailable while War of Emperium is in progress.',
        );
    }
}
