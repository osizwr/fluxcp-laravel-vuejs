<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ProbesServerReachability;
use App\Services\Server\ServerStatusProbe;
use App\Support\Rathena\CharMapServer;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up the rAthena side of the application.
 *
 * The registry is a singleton because it holds the request's current server
 * group, and its connections are registered during boot so that anything
 * resolving a connection by name -- models, migrations, console commands --
 * finds it already defined.
 */
final class RathenaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServerRegistry::class);
        $this->app->singleton(ProbesServerReachability::class, ServerStatusProbe::class);

        // Resolving the current group or char/map pair is common enough in
        // controllers and services to be worth binding directly.
        $this->app->bind(
            ServerGroup::class,
            fn ($app): ServerGroup => $app->make(ServerRegistry::class)->current(),
        );

        $this->app->bind(
            CharMapServer::class,
            fn ($app): CharMapServer => $app->make(ServerRegistry::class)->currentCharMapServer(),
        );
    }

    public function boot(): void
    {
        $this->app->make(ServerRegistry::class)->registerConnections();
    }
}
