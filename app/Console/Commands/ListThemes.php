<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ThemeNotFound;
use App\Services\Theme\Theme;
use App\Services\Theme\ThemeService;
use Illuminate\Console\Command;

/**
 * Shows the installed themes and which one is active.
 *
 * Exists so a misconfigured APP_THEME can be diagnosed without reading code,
 * and so a deployment can assert the active theme is the configured one rather
 * than discovering the fallback in production.
 */
final class ListThemes extends Command
{
    protected $signature = 'theme:list
                            {--strict : Fail if the active theme is not exactly the one configured}';

    protected $description = 'List installed themes and show which is active';

    public function handle(ThemeService $themes): int
    {
        $this->components->info("Theme directory: {$themes->relativeThemesPath()}");

        $installed = $themes->installed();

        if ($installed->isEmpty()) {
            $this->components->error(
                'No themes are installed. The panel cannot render without one.'
            );

            return self::FAILURE;
        }

        try {
            $active = $themes->active();
        } catch (ThemeNotFound $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['', 'Slug', 'Name', 'Version', 'Appearance', 'Stylesheet', 'Assets'],
            $installed->map(fn (Theme $theme): array => [
                $theme->slug === $active->slug ? '*' : '',
                $theme->slug,
                $theme->name,
                $theme->version,
                $theme->defaultAppearance ?? 'system',
                $theme->stylesheet() === null ? 'none' : 'yes',
                $theme->hasAssets() ? 'yes' : 'none',
            ])->values()->all(),
        );

        $configured = (string) config('theme.active');

        if (! $themes->activeIsExactlyAsConfigured()) {
            /*
             * Reported rather than left to be noticed. The panel still works,
             * but it is wearing a different skin from the one asked for, and
             * that is worth a non-zero exit in CI.
             */
            $this->components->warn(sprintf(
                "APP_THEME is '%s', which is not installed. Falling back to '%s'.",
                $configured,
                $active->slug,
            ));

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info("Active theme: {$active->name} ({$active->slug})");

        return self::SUCCESS;
    }
}
