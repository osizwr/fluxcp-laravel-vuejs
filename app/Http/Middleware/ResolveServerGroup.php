<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Rathena\ServerRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points the server registry at the group this request is working against.
 *
 * Must run before anything touches a model, because models take their database
 * connection from the registry.
 *
 * The group is read from the request when given and otherwise from the session,
 * which is how FluxCP's `preferred_server` parameter behaved: choosing a server
 * stuck for the rest of the visit. An unknown key is ignored rather than
 * fatal, since it usually means a stale bookmark or a server that has since
 * been removed from the configuration.
 */
final class ResolveServerGroup
{
    /**
     * The session key the chosen group is remembered under.
     */
    public const SESSION_KEY = 'rathena.server_group';

    public const CHAR_MAP_SESSION_KEY = 'rathena.char_map_server';

    public function __construct(private readonly ServerRegistry $servers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->input('server');
        $requestedPair = $request->input('world');

        $group = is_string($requested) && $this->servers->has($requested)
            ? $requested
            : $this->sessionValue($request, self::SESSION_KEY);

        $this->servers->use($group, null);

        $pair = is_string($requestedPair) && $this->servers->current()->hasCharMapServer($requestedPair)
            ? $requestedPair
            : $this->sessionValue($request, self::CHAR_MAP_SESSION_KEY);

        $this->servers->use(null, $pair);

        $this->remember($request);

        return $next($request);
    }

    private function sessionValue(Request $request, string $key): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Persist the resolved choice so it survives the rest of the visit.
     */
    private function remember(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, $this->servers->current()->key);
        $request->session()->put(self::CHAR_MAP_SESSION_KEY, $this->servers->currentCharMapServer()->key);
    }
}
