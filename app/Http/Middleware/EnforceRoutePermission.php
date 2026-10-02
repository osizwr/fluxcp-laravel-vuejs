<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccountLevel;
use App\Models\Account;
use App\Support\Authorization\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a request on the route's required privilege level.
 *
 * Unlike the legacy dispatcher, a route with no entry in the permission map is
 * refused rather than served. FluxCP's check returned -1 for an unknown
 * module/action and its dispatcher only blocked on a strict `false`, so ten
 * shipped actions -- three ranking ladders among them -- were reachable by
 * anyone. See docs/MIGRATION_DECISIONS.md (D3).
 */
final class EnforceRoutePermission
{
    public function __construct(private readonly PermissionRegistry $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return $this->denyUnmapped($request, '(unnamed route)');
        }

        $required = $this->permissions->routeRequirement($routeName);

        if ($required === null) {
            return $this->denyUnmapped($request, $routeName);
        }

        $account = $request->user();
        $level = $account instanceof Account
            ? $account->accountLevel()
            : AccountLevel::Unauthenticated;

        if ($level->satisfies($required)) {
            return $next($request);
        }

        return $this->deny($request, $required, $account instanceof Account);
    }

    /**
     * Refuse a route that carries no permission entry.
     *
     * This is a configuration fault rather than a visitor's mistake, so it is
     * logged at warning level: failing closed keeps it safe, but silently
     * returning 403 would make a missing entry very hard to diagnose.
     */
    private function denyUnmapped(Request $request, string $routeName): Response
    {
        Log::warning('Refused a request to a route with no permission entry.', [
            'route' => $routeName,
            'path' => $request->path(),
        ]);

        abort(Response::HTTP_FORBIDDEN, 'This page is not available.');
    }

    private function deny(Request $request, AccountLevel $required, bool $authenticated): Response
    {
        /*
         * A signed-in visitor hitting a guests-only route -- login,
         * registration, password reset -- has not done anything wrong, so
         * send them somewhere useful instead of showing an error. This is the
         * modern equivalent of the legacy panel routing them to its
         * `unauthorized` module.
         */
        if ($required === AccountLevel::Unauthenticated && $authenticated) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Already signed in.'], Response::HTTP_FORBIDDEN)
                : redirect()->intended('/');
        }

        /*
         * An unauthenticated visitor is asked to sign in rather than told the
         * page is forbidden, matching the legacy loginRequired() redirect and
         * avoiding disclosure of which pages exist for staff.
         */
        if (! $authenticated) {
            abort(Response::HTTP_UNAUTHORIZED, 'Please sign in to continue.');
        }

        abort(Response::HTTP_FORBIDDEN, 'You do not have permission to view this page.');
    }
}
