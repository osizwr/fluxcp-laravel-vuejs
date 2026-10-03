<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Theme\ClientBootstrap;
use App\Services\Theme\Theme;
use App\Services\Theme\ThemeService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the theme system into the application.
 *
 * The shell view receives the active theme and the client bootstrap payload,
 * so no controller has to know a theme system exists. Nothing else in the
 * application is aware of it either: the backend serves the same data and the
 * same API whichever skin is active.
 */
final class ThemeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThemeService::class);
        $this->app->singleton(ClientBootstrap::class);

        // Resolving the active theme is common enough in views and commands to
        // be worth binding directly.
        $this->app->bind(Theme::class, fn ($app): Theme => $app->make(ThemeService::class)->active());
    }

    public function boot(): void
    {
        /*
         * Shared lazily. A misconfigured APP_THEME should fail when a page is
         * rendered, with a clear message, rather than when the container boots
         * -- otherwise every artisan command dies too, including the ones an
         * operator would use to diagnose it.
         */
        View::composer('app', function ($view): void {
            $themes = $this->app->make(ThemeService::class);

            $view->with([
                'theme' => $themes->active(),
                'bootstrapJson' => $this->app->make(ClientBootstrap::class)->toJson(),
            ]);
        });
    }
}
