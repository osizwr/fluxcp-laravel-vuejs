<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Exceptions\ThemeNotFound;
use App\Services\Theme\ThemeService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Choosing a theme.
 *
 * The behaviour that matters is that a misconfigured APP_THEME is loud. A
 * panel quietly wearing the wrong skin is harder to diagnose than one that
 * refuses to render and says which themes exist.
 */
final class ThemeResolutionTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryThemes = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryThemes as $directory) {
            File::deleteDirectory($directory);
        }

        $this->temporaryThemes = [];

        parent::tearDown();
    }

    private function themes(): ThemeService
    {
        $service = $this->app->make(ThemeService::class);
        $service->flush();

        return $service;
    }

    /**
     * Create a throwaway theme on disk.
     *
     * @param  array<string, mixed>|string  $manifest  Raw string to write
     *                                                 malformed JSON.
     */
    private function makeTheme(string $slug, array|string $manifest = [], bool $withStylesheet = false): string
    {
        $directory = base_path('resources/themes/'.$slug);

        File::ensureDirectoryExists($directory);
        $this->temporaryThemes[] = $directory;

        File::put(
            $directory.'/theme.json',
            is_string($manifest)
                ? $manifest
                : (string) json_encode(array_merge(
                    ['name' => ucfirst($slug), 'version' => '1.0.0'],
                    $manifest,
                )),
        );

        if ($withStylesheet) {
            File::ensureDirectoryExists($directory.'/styles');
            File::put($directory.'/styles/theme.css', '/* test */');
        }

        return $directory;
    }

    /*
    |--------------------------------------------------------------------------
    | The shipped theme
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_fantasy_theme_is_installed_and_resolves(): void
    {
        config(['theme.active' => 'fantasy']);

        $theme = $this->themes()->active();

        $this->assertSame('fantasy', $theme->slug);
        $this->assertSame('Fantasy', $theme->name);
        $this->assertTrue($theme->supports('dark_mode'));
        $this->assertTrue($theme->supports('mobile'));
        // Art-directed for dark, so a first visit should not get the light variant.
        $this->assertSame('dark', $theme->defaultAppearance);
    }

    #[Test]
    public function the_fantasy_theme_ships_a_stylesheet_entrypoint(): void
    {
        config(['theme.active' => 'fantasy']);

        $this->assertSame(
            'resources/themes/fantasy/styles/theme.css',
            $this->themes()->active()->stylesheet(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Switching
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function changing_the_configured_theme_changes_the_active_one(): void
    {
        // The core promise: a different value, a different skin, no code change.
        $this->makeTheme('switch-target', ['name' => 'Switch Target'], withStylesheet: true);

        config(['theme.active' => 'switch-target']);

        $theme = $this->themes()->active();

        $this->assertSame('switch-target', $theme->slug);
        $this->assertSame('Switch Target', $theme->name);
        $this->assertSame('resources/themes/switch-target/styles/theme.css', $theme->stylesheet());
    }

    #[Test]
    public function a_theme_without_a_stylesheet_is_still_valid(): void
    {
        // Legitimate for a theme that only overrides components.
        $this->makeTheme('no-styles');

        config(['theme.active' => 'no-styles']);

        $this->assertNull($this->themes()->active()->stylesheet());
    }

    #[Test]
    public function it_lists_every_installed_theme(): void
    {
        $this->makeTheme('listed-one');
        $this->makeTheme('listed-two');

        $installed = $this->themes()->installed();

        $this->assertTrue($installed->has('fantasy'));
        $this->assertTrue($installed->has('listed-one'));
        $this->assertTrue($installed->has('listed-two'));
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_missing_theme_falls_back_and_says_so(): void
    {
        config(['theme.active' => 'does-not-exist', 'theme.fallback' => 'fantasy', 'theme.strict' => false]);

        $themes = $this->themes();

        $this->assertSame('fantasy', $themes->active()->slug);
        // Reported, not silent: the operator asked for something else.
        $this->assertFalse($themes->activeIsExactlyAsConfigured());
    }

    #[Test]
    public function a_missing_theme_throws_when_no_fallback_is_configured(): void
    {
        config(['theme.active' => 'does-not-exist', 'theme.fallback' => null]);

        $this->expectException(ThemeNotFound::class);
        // The message has to be useful enough to act on.
        $this->expectExceptionMessageMatches('/does-not-exist/');
        $this->expectExceptionMessageMatches('/Installed themes:/');

        $this->themes()->active();
    }

    #[Test]
    public function a_missing_theme_throws_in_strict_mode_even_with_a_fallback(): void
    {
        // So a deployment fails rather than shipping the wrong skin.
        config(['theme.active' => 'does-not-exist', 'theme.fallback' => 'fantasy', 'theme.strict' => true]);

        $this->expectException(ThemeNotFound::class);

        $this->themes()->active();
    }

    #[Test]
    public function a_theme_with_malformed_json_is_ignored_rather_than_fatal(): void
    {
        // One broken theme must not take the site down when a working one is
        // selected.
        $this->makeTheme('broken-json', '{ not json');

        config(['theme.active' => 'fantasy']);

        $themes = $this->themes();

        $this->assertFalse($themes->installed()->has('broken-json'));
        $this->assertSame('fantasy', $themes->active()->slug);
    }

    #[Test]
    public function a_theme_missing_a_required_field_is_ignored(): void
    {
        $this->makeTheme('no-version', ['name' => 'No Version', 'version' => '']);

        $this->assertFalse($this->themes()->installed()->has('no-version'));
    }

    #[Test]
    public function a_theme_whose_declared_slug_contradicts_its_directory_is_ignored(): void
    {
        // Otherwise the asset and override paths, which are built from the
        // directory name, would disagree with the manifest.
        $this->makeTheme('honest-directory', ['slug' => 'something-else']);

        $this->assertFalse($this->themes()->installed()->has('honest-directory'));
    }

    #[Test]
    public function a_theme_name_that_is_not_a_plain_directory_name_is_refused(): void
    {
        // The value reaches a filesystem path and can come from the
        // environment, so traversal must not resolve.
        config(['theme.active' => '../../etc', 'theme.fallback' => null]);

        $this->expectException(ThemeNotFound::class);

        $this->themes()->active();
    }

    /*
    |--------------------------------------------------------------------------
    | Configurable directory
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_theme_directory_is_configurable(): void
    {
        $directory = base_path('resources/alt-themes/relocated');
        File::ensureDirectoryExists($directory);
        $this->temporaryThemes[] = base_path('resources/alt-themes');

        File::put($directory.'/theme.json', (string) json_encode([
            'name' => 'Relocated',
            'version' => '2.0.0',
        ]));

        config([
            'theme.path' => 'resources/alt-themes',
            'theme.active' => 'relocated',
            'theme.fallback' => null,
        ]);

        $themes = $this->themes();

        $this->assertSame('resources/alt-themes', $themes->relativeThemesPath());
        $this->assertSame('Relocated', $themes->active()->name);
        // And the shipped theme is no longer visible, because it is elsewhere.
        $this->assertFalse($themes->installed()->has('fantasy'));
    }

    /*
    |--------------------------------------------------------------------------
    | The console command
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_list_command_reports_the_active_theme(): void
    {
        config(['theme.active' => 'fantasy']);

        $this->artisan('theme:list')
            ->expectsOutputToContain('fantasy')
            ->assertSuccessful();
    }

    #[Test]
    public function the_list_command_fails_in_strict_mode_when_the_fallback_is_in_use(): void
    {
        config(['theme.active' => 'does-not-exist', 'theme.fallback' => 'fantasy', 'theme.strict' => false]);

        $this->artisan('theme:list', ['--strict' => true])->assertFailed();
    }
}
