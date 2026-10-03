<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Theme\Theme;
use App\Services\Theme\ThemeService;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Copies themes' asset directories into public/themes/<slug>/.
 *
 * Most theme assets never need this: anything referenced from a theme's CSS or
 * a `.vue` file is bundled and hashed by Vite, which is better, because it is
 * cache-safe and needs no deployment step.
 *
 * This exists for the other case -- an asset that needs a *stable, knowable*
 * URL, because something outside the bundle points at it. A logo an operator
 * sets GAME_LOGO to is the motivating example: they cannot write a hashed
 * filename into their .env.
 */
final class PublishThemeAssets extends Command
{
    protected $signature = 'theme:publish
                            {--theme= : Only publish this theme}
                            {--force : Overwrite files that already exist}';

    protected $description = "Copy themes' asset directories into public/themes";

    public function handle(ThemeService $themes, Filesystem $files): int
    {
        $only = $this->option('theme');

        if ($only !== null && ! $themes->exists((string) $only)) {
            $this->components->error("No theme named '{$only}' is installed.");

            return self::FAILURE;
        }

        $selected = $themes->installed()
            ->when($only !== null, fn ($all) => $all->only([(string) $only]))
            ->filter(fn (Theme $theme): bool => $theme->hasAssets());

        if ($selected->isEmpty()) {
            $this->components->info('No themes have an assets directory to publish.');

            return self::SUCCESS;
        }

        foreach ($selected as $theme) {
            $source = $theme->directory.DIRECTORY_SEPARATOR.'assets';
            $target = public_path('themes'.DIRECTORY_SEPARATOR.$theme->slug.DIRECTORY_SEPARATOR.'assets');

            if ($files->exists($target) && ! $this->option('force')) {
                $this->components->warn(
                    "{$theme->slug}: already published at public/themes/{$theme->slug}/assets. "
                    .'Pass --force to overwrite.'
                );

                continue;
            }

            $files->ensureDirectoryExists(dirname($target));

            /*
             * copyDirectory() rather than a symlink. A symlink would track
             * edits during development, but it also means a deployment that
             * ships only public/ ends up with a dangling link, and the failure
             * shows up as missing images in production.
             */
            $files->copyDirectory($source, $target);

            $this->components->info("{$theme->slug}: published to public/themes/{$theme->slug}/assets");
        }

        $this->newLine();
        $this->line(
            '  <fg=gray>Reference a published asset by URL, for example</>'
            ."\n  <fg=gray>GAME_LOGO=/themes/".($selected->keys()->first() ?? 'my-theme')
            .'/assets/images/logo.png</>'
        );

        return self::SUCCESS;
    }
}
