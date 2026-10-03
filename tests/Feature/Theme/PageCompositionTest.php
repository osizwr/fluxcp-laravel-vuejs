<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Services\Theme\ClientBootstrap;
use App\Services\Theme\Theme;
use App\Services\Theme\ThemeService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Page composition: a theme deciding which sections a page has and in what
 * order.
 *
 * This is the part of the theme system that goes beyond styling. A theme
 * declares a page as an ordered list of block names, and the client renders
 * that list; reordering a page is editing configuration, not a component.
 *
 * The order is asserted explicitly, because a composition that rendered its
 * blocks in some other order would still "work" and would be wrong.
 */
final class PageCompositionTest extends TestCase
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
     * @param  array<string, mixed>  $manifest
     */
    private function makeTheme(string $slug, array $manifest): string
    {
        $directory = base_path('resources/themes/'.$slug);

        File::ensureDirectoryExists($directory);
        $this->temporaryThemes[] = $directory;

        File::put($directory.'/theme.json', (string) json_encode(array_merge(
            ['name' => ucfirst($slug), 'version' => '1.0.0'],
            $manifest,
        )));

        return $directory;
    }

    /*
    |--------------------------------------------------------------------------
    | The shipped composition
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_fantasy_theme_composes_its_home_page_from_blocks(): void
    {
        config(['theme.active' => 'fantasy']);

        $pages = $this->themes()->active()->pages;

        $this->assertArrayHasKey('home', $pages);
        $this->assertSame('public', $pages['home']['layout']);
        $this->assertNotEmpty($pages['home']['blocks']);
    }

    #[Test]
    public function the_home_page_blocks_are_in_the_declared_order(): void
    {
        // The whole point of a composition. A different order here is a
        // different page.
        config(['theme.active' => 'fantasy']);

        $blocks = array_column($this->themes()->active()->pages['home']['blocks'], 'block');

        $this->assertSame([
            'hero',
            'server-status',
            'statistics',
            'class-showcase',
            'ranking-showcase',
            'news-section',
            'feature-grid',
            'call-to-action',
        ], $blocks);
    }

    #[Test]
    public function block_props_from_the_composition_are_preserved(): void
    {
        // Presentation options belong to the composition, not the block, so a
        // theme can show five ranks where another shows ten.
        config(['theme.active' => 'fantasy']);

        $blocks = collect($this->themes()->active()->pages['home']['blocks'])->keyBy('block');

        $this->assertSame(['detailed' => true], (array) $blocks['server-status']['props']);
        $this->assertSame(['limit' => 6], (array) $blocks['class-showcase']['props']);
        $this->assertSame(
            ['ladder' => 'level', 'limit' => 5],
            (array) $blocks['ranking-showcase']['props'],
        );
        // A block declared as a bare string gets no props, which is the common case.
        $this->assertSame([], (array) $blocks['hero']['props']);
    }

    #[Test]
    public function the_composition_reaches_the_browser(): void
    {
        config(['theme.active' => 'fantasy']);

        $payload = $this->app->make(ClientBootstrap::class)->toArray();
        $pages = (array) $payload['theme']['pages'];

        $this->assertArrayHasKey('home', $pages);
        $this->assertSame(
            'hero',
            $pages['home']['blocks'][0]['block'],
            'The client must receive the blocks in order.',
        );
    }

    #[Test]
    public function the_theme_declares_its_layout_roles(): void
    {
        config(['theme.active' => 'fantasy']);

        $theme = $this->themes()->active();

        $this->assertSame('PublicLayout', $theme->layoutFor('public'));
        $this->assertSame('AuthLayout', $theme->layoutFor('auth'));
        $this->assertNull($theme->layoutFor('admin'), 'No admin panel exists to lay out.');
    }

    /*
    |--------------------------------------------------------------------------
    | A different theme, a different structure
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function another_theme_can_declare_a_completely_different_page(): void
    {
        /*
         * The architectural claim: two themes, the same backend, different
         * page structure. Nothing about the application changes.
         */
        $this->makeTheme('restructured', [
            'pages' => [
                'home' => [
                    'layout' => 'app',
                    'blocks' => [
                        'ranking-showcase',
                        ['block' => 'news-section', 'props' => ['limit' => 10]],
                        'hero',
                    ],
                ],
            ],
        ]);

        config(['theme.active' => 'restructured']);

        $page = $this->themes()->active()->pages['home'];

        $this->assertSame('app', $page['layout']);
        $this->assertSame(
            ['ranking-showcase', 'news-section', 'hero'],
            array_column($page['blocks'], 'block'),
        );
        $this->assertSame(['limit' => 10], (array) $page['blocks'][1]['props']);
    }

    #[Test]
    public function a_theme_may_declare_no_composition_at_all(): void
    {
        // Then every page falls back to the application's own, which is how a
        // palette-only theme like `slate` works.
        config(['theme.active' => 'slate']);

        $this->assertSame([], $this->themes()->active()->pages);
    }

    #[Test]
    public function a_composition_may_use_the_same_block_twice(): void
    {
        // Two ladders on one page, for instance.
        $this->makeTheme('twice', [
            'pages' => [
                'home' => [
                    'blocks' => [
                        ['block' => 'ranking-showcase', 'props' => ['ladder' => 'level']],
                        ['block' => 'ranking-showcase', 'props' => ['ladder' => 'zeny']],
                    ],
                ],
            ],
        ]);

        config(['theme.active' => 'twice']);

        $blocks = $this->themes()->active()->pages['home']['blocks'];

        $this->assertCount(2, $blocks);
        $this->assertSame('level', ((array) $blocks[0]['props'])['ladder']);
        $this->assertSame('zeny', ((array) $blocks[1]['props'])['ladder']);
    }

    #[Test]
    public function a_page_without_a_layout_defaults_to_the_application_shell(): void
    {
        $this->makeTheme('nolayout', [
            'pages' => ['home' => ['blocks' => ['hero']]],
        ]);

        config(['theme.active' => 'nolayout']);

        $this->assertSame('app', $this->themes()->active()->pages['home']['layout']);
    }

    /*
    |--------------------------------------------------------------------------
    | Malformed compositions are refused
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_page_with_no_blocks_is_refused(): void
    {
        // It would render a blank page, which is never what was meant.
        $this->makeTheme('emptypage', [
            'pages' => ['home' => ['layout' => 'public', 'blocks' => []]],
        ]);

        config(['theme.active' => 'fantasy']);

        // Rejected at parse time, so the theme is skipped rather than serving
        // an empty page.
        $this->assertFalse($this->themes()->installed()->has('emptypage'));
    }

    #[Test]
    public function a_block_name_that_is_not_kebab_case_is_refused(): void
    {
        /*
         * Block names are translated to PascalCase filenames, so an arbitrary
         * string could reach a path. Validating the shape keeps that
         * translation total.
         */
        $this->makeTheme('badblock', [
            'pages' => ['home' => ['blocks' => ['../../etc/passwd']]],
        ]);

        config(['theme.active' => 'fantasy']);

        $this->assertFalse($this->themes()->installed()->has('badblock'));
    }

    #[Test]
    public function a_layout_that_is_not_a_bare_component_name_is_refused(): void
    {
        $this->makeTheme('badlayout', [
            'layouts' => ['public' => '../../../etc/passwd'],
        ]);

        config(['theme.active' => 'fantasy']);

        $this->assertFalse($this->themes()->installed()->has('badlayout'));
    }

    #[Test]
    public function the_parse_error_names_the_theme_and_the_page(): void
    {
        // So an operator can find it. A rejection with no location is only
        // marginally better than a silent one.
        $directory = $this->makeTheme('explainme', [
            'pages' => ['home' => ['blocks' => []]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/explainme/');
        $this->expectExceptionMessageMatches("/'home'/");

        Theme::fromDirectory($directory, 'resources/themes/explainme');
    }
}
