<?php

declare(strict_types=1);

namespace App\Services\Theme;

use App\Exceptions\ThemeNotFound;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Decides which theme is active, and is the only thing that does.
 *
 * Everything else asks this service. Nothing in the application branches on a
 * theme's name -- no `if ($theme === 'fantasy')` anywhere -- because a theme
 * that needed the application to know about it would not be a theme, it would
 * be a fork.
 *
 * Resolution order:
 *
 *   config('theme.active')         what the operator asked for
 *     -> found?                    use it
 *     -> missing, strict off,
 *        fallback resolves?        use the fallback and log a warning
 *     -> otherwise                 throw ThemeNotFound
 *
 * The middle case logs rather than staying quiet, so a typo in APP_THEME shows
 * up as a warning in the log instead of as an unexplained change of skin.
 */
final class ThemeService
{
    /** @var Collection<string, Theme>|null */
    private ?Collection $installed = null;

    private ?Theme $active = null;

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * The theme this request is rendered with.
     *
     * @throws ThemeNotFound
     */
    public function active(): Theme
    {
        if ($this->active instanceof Theme) {
            return $this->active;
        }

        $requested = (string) $this->config->get('theme.active', '');
        $theme = $this->find($requested);

        if ($theme instanceof Theme) {
            return $this->active = $theme;
        }

        $fallbackSlug = $this->config->get('theme.fallback');
        $strict = (bool) $this->config->get('theme.strict', false);

        if (! $strict && is_string($fallbackSlug) && $fallbackSlug !== '' && $fallbackSlug !== $requested) {
            $fallback = $this->find($fallbackSlug);

            if ($fallback instanceof Theme) {
                Log::warning('The configured theme was not found; using the fallback.', [
                    'requested' => $requested,
                    'fallback' => $fallback->slug,
                    'path' => $this->themesPath(),
                ]);

                return $this->active = $fallback;
            }
        }

        throw ThemeNotFound::named($requested, $this->themesPath(), $this->installed()->keys()->all());
    }

    /**
     * Whether the configured theme resolves without falling back.
     *
     * Used by `theme:list` and by a deployment check that wants to fail rather
     * than discover the fallback in production.
     */
    public function activeIsExactlyAsConfigured(): bool
    {
        return $this->find((string) $this->config->get('theme.active', '')) instanceof Theme;
    }

    public function find(string $slug): ?Theme
    {
        if ($slug === '' || ! $this->isSafeSlug($slug)) {
            return null;
        }

        return $this->installed()->get($slug);
    }

    public function exists(string $slug): bool
    {
        return $this->find($slug) instanceof Theme;
    }

    /**
     * Every readable theme in the theme directory.
     *
     * A directory that looks like a theme but has a broken theme.json is
     * skipped and logged, not fatal: one malformed theme should not take the
     * site down when a working one is selected.
     *
     * @return Collection<string, Theme>
     */
    public function installed(): Collection
    {
        if ($this->installed instanceof Collection) {
            return $this->installed;
        }

        $root = $this->themesPath();
        $themes = Collection::make();

        if (! is_dir($root)) {
            return $this->installed = $themes;
        }

        foreach ((array) glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) as $directory) {
            if (! is_string($directory)) {
                continue;
            }

            $slug = basename($directory);

            if (! $this->isSafeSlug($slug)) {
                continue;
            }

            try {
                $theme = Theme::fromDirectory(
                    $directory,
                    $this->relativeThemesPath().'/'.$slug,
                );
            } catch (RuntimeException $e) {
                Log::warning('Ignoring an unreadable theme.', [
                    'directory' => $directory,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            $themes->put($slug, $theme);
        }

        return $this->installed = $themes->sortKeys();
    }

    /**
     * Absolute path to the theme directory.
     */
    public function themesPath(): string
    {
        return base_path($this->relativeThemesPath());
    }

    public function relativeThemesPath(): string
    {
        return trim((string) $this->config->get('theme.path', 'resources/themes'), '/\\');
    }

    /**
     * Forget what has been resolved.
     *
     * Needed by tests that change the configuration, and by `theme:publish`
     * after it writes new directories.
     */
    public function flush(): void
    {
        $this->installed = null;
        $this->active = null;
    }

    /**
     * Reject anything that is not a plain directory name.
     *
     * The value comes from configuration, which on a misconfigured deployment
     * can come from the environment, so a slug containing a path separator or
     * a traversal sequence must not be joined onto a filesystem path.
     */
    private function isSafeSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug) === 1;
    }
}
