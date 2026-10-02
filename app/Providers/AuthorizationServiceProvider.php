<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\AccountLevel;
use App\Models\Account;
use App\Services\Auth\AccountUserProvider;
use App\Services\Auth\RathenaCredentialVerifier;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the account authentication driver and turns the ability map into
 * gates.
 *
 * Defining a gate per ability means authorisation is checked through
 * Laravel's own mechanism -- $user->can(), the Gate facade, the `can`
 * middleware, policies -- rather than through a bespoke helper, while the
 * levels themselves still come from the one configuration file ported from
 * FluxCP's access.php.
 */
final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
    }

    public function boot(): void
    {
        $this->registerAuthProvider();
        $this->registerAbilityGates();
    }

    private function registerAuthProvider(): void
    {
        Auth::provider('rathena-accounts', fn ($app): AccountUserProvider => new AccountUserProvider(
            $app->make(ServerRegistry::class),
            $app->make(RathenaCredentialVerifier::class),
            $app->make(Hasher::class),
        ));
    }

    /**
     * Define a gate for every ability in the permission map.
     *
     * The closure's parameter is nullable so that Laravel evaluates the gate
     * for guests too, instead of short-circuiting to denied. That matters
     * because some abilities are legitimately granted to everyone -- the
     * legacy map has SearchWhosOnline and SeeItemDbScripts at ANYONE -- and a
     * guest must be able to use them.
     */
    private function registerAbilityGates(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);

        foreach ($registry->abilityMap() as $ability => $required) {
            Gate::define(
                $ability,
                static fn (?Account $account): bool => self::levelOf($account)->satisfies($required),
            );
        }
    }

    /**
     * The level to authorise against, treating a guest as Unauthenticated.
     *
     * This mirrors the legacy session, which gave a signed-out visitor
     * group_level = AccountLevel::UNAUTH rather than leaving it unset.
     */
    private static function levelOf(?Account $account): AccountLevel
    {
        return $account?->accountLevel() ?? AccountLevel::Unauthenticated;
    }
}
