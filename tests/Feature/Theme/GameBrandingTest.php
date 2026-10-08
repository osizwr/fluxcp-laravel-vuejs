<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Models\Account;
use App\Services\Theme\ClientBootstrap;
use App\Services\Theme\ThemeService;
use App\Support\Tokens\OneTimeCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Branding, and the boundary between the server's configuration and the
 * browser's copy of it.
 *
 * Two separate concerns are checked here:
 *
 *   - GAME_NAME and friends reach the page, so an operator can rebrand without
 *     editing a Vue component.
 *   - Only safe values reach the page. The Reverb secret is the one that would
 *     actually matter, and its absence is asserted directly rather than
 *     assumed from the shape of the code.
 */
final class GameBrandingTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return $this->app->make(ClientBootstrap::class)->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Branding is configuration
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_game_name_reaches_the_rendered_page(): void
    {
        config(['game.name' => 'My Ragnarok Online', 'game.short_name' => 'MRO']);

        $response = $this->get('/')->assertOk();

        $response->assertSee('My Ragnarok Online', escape: false);
        // In the title as well as the payload, so a browser tab is branded too.
        $response->assertSee('<title>My Ragnarok Online</title>', escape: false);
    }

    #[Test]
    public function changing_the_game_name_requires_no_component_change(): void
    {
        config(['game.name' => 'Second Server']);

        $this->assertSame('Second Server', $this->payload()['game']['name']);

        config(['game.name' => 'Third Server']);
        $this->app->forgetInstance(ClientBootstrap::class);

        $this->assertSame('Third Server', $this->payload()['game']['name']);
    }

    #[Test]
    public function the_game_name_is_not_hardcoded_in_any_frontend_file(): void
    {
        /*
         * The guarantee behind "rebrand without editing Vue". A component that
         * wrote the default name in would keep working and look correct on this
         * install, and be wrong on every other one.
         */
        $files = array_merge(
            (array) glob(resource_path('js/**/*.vue')) ?: [],
            (array) glob(resource_path('js/*.vue')) ?: [],
            (array) glob(resource_path('themes/*/**/*.vue')) ?: [],
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            if (! is_string($file)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'Ragnarok Online',
                (string) file_get_contents($file),
                basename($file).' hardcodes the game name; read it from useGame() instead.',
            );
        }
    }

    #[Test]
    public function only_configured_links_are_published(): void
    {
        config([
            'game.links' => [
                'discord' => 'https://discord.example/invite',
                'downloads' => null,
                'forum' => '',
            ],
        ]);

        $links = (array) $this->payload()['game']['links'];

        $this->assertSame(['discord' => 'https://discord.example/invite'], $links);
        // An unset link is an absent navigation item, not a dead one.
        $this->assertArrayNotHasKey('downloads', $links);
        $this->assertArrayNotHasKey('forum', $links);
    }

    /*
    |--------------------------------------------------------------------------
    | The payload boundary
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_payload_carries_the_active_theme(): void
    {
        config(['theme.active' => 'fantasy']);

        $theme = $this->payload()['theme'];

        $this->assertSame('fantasy', $theme['slug']);
        $this->assertSame('dark', $theme['defaultAppearance']);
        $this->assertArrayHasKey('supports', $theme);
    }

    #[Test]
    public function the_theme_slug_is_on_the_html_element(): void
    {
        // What the theme stylesheets key their overrides off, so it has to be
        // present for the skin to apply at all.
        $this->get('/')->assertOk()->assertSee('data-theme-slug="fantasy"', escape: false);
    }

    #[Test]
    public function the_active_themes_stylesheet_is_linked(): void
    {
        $entry = $this->app->make(ThemeService::class)->active()->stylesheet();

        $this->assertNotNull($entry, 'The fantasy theme should ship a stylesheet.');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertIsString($html);

        /*
         * Accepts either form, because both are correct depending on how the
         * frontend is being served:
         *
         *   npm run dev    Vite emits the source path from its dev server
         *   npm run build  Blade emits the hashed file from the manifest
         *
         * An earlier version of this test keyed off whether a manifest existed,
         * which was the wrong signal: a developer running the suite with the
         * dev server up has both.
         */
        $manifestPath = public_path('build/manifest.json');
        $hashed = null;

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            $this->assertIsArray($manifest);
            $this->assertArrayHasKey(
                $entry,
                $manifest,
                'The theme stylesheet is not a Vite entrypoint. Check themeStylesheets() in vite.config.ts.',
            );

            $hashed = (string) $manifest[$entry]['file'];
        }

        $linked = str_contains($html, $entry)
            || ($hashed !== null && str_contains($html, $hashed));

        $this->assertTrue(
            $linked,
            "The page links neither '{$entry}' nor its built output.",
        );
    }

    #[Test]
    public function the_reverb_secret_never_reaches_the_browser(): void
    {
        /*
         * The one value in this payload that would matter. The app key is
         * public by design -- it is a client identifier, like a Pusher key --
         * but the secret signs the server's calls to the websocket server.
         */
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'public-app-key',
            'broadcasting.connections.reverb.secret' => 'super-secret-value',
            'broadcasting.connections.reverb.app_id' => '123456',
            'broadcasting.connections.reverb.options' => [
                'host' => 'localhost',
                'port' => 8080,
                'scheme' => 'http',
            ],
        ]);

        $json = $this->app->make(ClientBootstrap::class)->toJson();

        $this->assertStringContainsString('public-app-key', $json);
        $this->assertStringNotContainsString('super-secret-value', $json);
        $this->assertStringNotContainsString('secret', $json);
        // The app id is not needed by the client either.
        $this->assertStringNotContainsString('123456', $json);

        $this->get('/')->assertOk()->assertDontSee('super-secret-value', escape: false);
    }

    #[Test]
    public function the_confirmation_code_length_is_published_from_the_generator(): void
    {
        /*
         * The confirmation form draws one input per digit, so it cannot work
         * from a number written into a component -- six boxes would outlive a
         * change to five, and a visitor would be given one box too many for a
         * code that no longer fits it.
         *
         * Asserting against the constant rather than against 6 is the point:
         * this fails if the payload is ever hardcoded away from the class that
         * generates the codes.
         */
        $this->assertSame(
            OneTimeCode::LENGTH,
            $this->payload()['accounts']['confirmationCodeLength'],
        );
    }

    #[Test]
    public function broadcasting_is_null_when_it_is_not_configured(): void
    {
        // Meaningful to the client: it polls instead of attempting a
        // connection that cannot succeed.
        config(['broadcasting.default' => 'log']);

        $this->assertNull($this->payload()['broadcasting']);
    }

    #[Test]
    public function broadcasting_is_null_when_reverb_has_no_key(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => null,
        ]);

        $this->assertNull($this->payload()['broadcasting']);
    }

    /*
    |--------------------------------------------------------------------------
    | The download page's contents
    |--------------------------------------------------------------------------
    |
    | Operator-authored configuration, like the feature list, so the same two
    | questions apply: does what they wrote reach the browser, and is what they
    | left half-finished dropped before it gets there.
    |
    */

    #[Test]
    public function the_configured_downloads_reach_the_browser(): void
    {
        config(['game.downloads' => [
            'notice' => '  Mirrors rebuilt this morning.  ',
            'clients' => [[
                'name' => 'Full client',
                'platform' => 'windows',
                'badge' => 'New',
                'version' => '1.2.0',
                'size' => '5.6 GB',
                'updated' => '7 October',
                'description' => 'Everything needed to play.',
                'mirrors' => [['label' => 'Mega', 'url' => 'https://mega.example/client']],
                'notes' => ['Extract before running.'],
            ]],
            'requirements' => [[
                'heading' => 'Minimum',
                'rows' => [['label' => 'Memory', 'value' => '2 GB RAM']],
            ]],
            'steps' => [['title' => 'Download', 'description' => 'Pick a mirror.']],
        ]]);

        $downloads = $this->payload()['downloads'];

        $this->assertSame('Mirrors rebuilt this morning.', $downloads['notice']);
        $this->assertCount(1, $downloads['clients']);

        $client = $downloads['clients'][0];

        $this->assertSame('Full client', $client['name']);
        $this->assertSame('windows', $client['platform']);
        $this->assertSame('New', $client['badge']);
        $this->assertSame('5.6 GB', $client['size']);
        $this->assertSame(
            [['label' => 'Mega', 'url' => 'https://mega.example/client']],
            $client['mirrors'],
        );
        $this->assertSame(['Extract before running.'], $client['notes']);

        $this->assertSame(
            [['heading' => 'Minimum', 'rows' => [['label' => 'Memory', 'value' => '2 GB RAM']]]],
            $downloads['requirements'],
        );
        $this->assertSame(
            [['title' => 'Download', 'description' => 'Pick a mirror.']],
            $downloads['steps'],
        );
    }

    #[Test]
    public function a_mirror_without_a_url_is_not_published(): void
    {
        /*
         * A labelled button that leads nowhere is worse than an absent one:
         * somebody clicks it, nothing happens, and they conclude the download
         * is broken rather than unpublished.
         */
        config(['game.downloads.clients' => [[
            'name' => 'Full client',
            'mirrors' => [
                ['label' => 'Mega', 'url' => 'https://mega.example/client'],
                ['label' => 'MediaFire', 'url' => null],
                ['label' => '', 'url' => 'https://nowhere.example/file'],
            ],
        ]]]);

        $mirrors = $this->payload()['downloads']['clients'][0]['mirrors'];

        $this->assertSame([['label' => 'Mega', 'url' => 'https://mega.example/client']], $mirrors);
    }

    #[Test]
    public function a_package_with_no_mirrors_is_still_published(): void
    {
        /*
         * The state every install starts in. The card is drawn and marked as
         * not yet available, so an operator filling these in can see the shape
         * of the page they are building.
         */
        config(['game.downloads.clients' => [
            ['name' => 'Android', 'platform' => 'android', 'mirrors' => []],
            ['name' => '', 'mirrors' => [['label' => 'Mega', 'url' => 'https://mega.example/x']]],
        ]]);

        $clients = $this->payload()['downloads']['clients'];

        // The nameless one is dropped: there is nothing to label its card with.
        $this->assertCount(1, $clients);
        $this->assertSame('Android', $clients[0]['name']);
        $this->assertSame([], $clients[0]['mirrors']);
    }

    #[Test]
    public function an_unknown_platform_is_published_as_none(): void
    {
        // The client draws an icon per platform. A typo should cost the icon,
        // not show the wrong operating system's.
        config(['game.downloads.clients' => [
            ['name' => 'Client', 'platform' => 'windoze', 'mirrors' => []],
        ]]);

        $this->assertNull($this->payload()['downloads']['clients'][0]['platform']);
    }

    #[Test]
    public function half_filled_requirements_and_steps_are_dropped(): void
    {
        config(['game.downloads' => [
            'requirements' => [
                ['heading' => 'Minimum', 'rows' => []],
                ['heading' => null, 'rows' => [['label' => 'Memory', 'value' => '2 GB']]],
                [
                    'heading' => 'Android',
                    'rows' => [
                        ['label' => 'Memory', 'value' => '3 GB'],
                        ['label' => 'Storage', 'value' => null],
                    ],
                ],
            ],
            'steps' => [
                ['title' => null, 'description' => 'Orphaned.'],
                ['title' => 'Launch'],
            ],
        ]]);

        $downloads = $this->payload()['downloads'];

        // An empty tab and a headless one are both gaps in the page.
        $this->assertSame(
            [['heading' => 'Android', 'rows' => [['label' => 'Memory', 'value' => '3 GB']]]],
            $downloads['requirements'],
        );
        $this->assertSame([['title' => 'Launch', 'description' => '']], $downloads['steps']);
    }

    #[Test]
    public function a_server_with_nothing_configured_publishes_empty_lists(): void
    {
        // Not null, and not absent: the page distinguishes "nothing published
        // yet" from "the payload failed to arrive", and can only do that if
        // the key is always there.
        config(['game.downloads' => []]);

        $this->assertSame(
            ['notice' => null, 'clients' => [], 'requirements' => [], 'steps' => []],
            $this->payload()['downloads'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The theme changes nothing on the server
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_api_is_identical_whichever_theme_is_active(): void
    {
        /*
         * The architectural claim: a theme is presentation. If switching one
         * changed an API response, business logic would have leaked into it.
         */
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        config(['theme.active' => 'fantasy']);
        $withFantasy = $this->getJson('/api/rankings/level')->assertOk()->json();

        config(['theme.active' => 'does-not-exist']); // falls back, still renders
        $this->app->forgetInstance(ThemeService::class);
        $withFallback = $this->getJson('/api/rankings/level')->assertOk()->json();

        $this->assertSame($withFantasy, $withFallback);
    }

    #[Test]
    public function authentication_works_regardless_of_theme(): void
    {
        Account::factory()->named('merchant')->withPassword('Zeny4Days!')->create();

        config(['theme.active' => 'fantasy']);

        $this->postJson('/api/auth/login', [
            'username' => 'merchant',
            'password' => 'Zeny4Days!',
        ])->assertOk();

        $this->assertAuthenticated();
    }

    #[Test]
    public function no_theme_file_touches_the_database_or_authorisation(): void
    {
        /*
         * Themes are presentation. A query, a policy check or a credential in a
         * theme file would make the skin load-bearing, and the next theme would
         * silently drop whatever it was doing.
         */
        $files = array_merge(
            (array) glob(resource_path('themes/*/**/*.vue')) ?: [],
            (array) glob(resource_path('themes/*/*.vue')) ?: [],
        );

        $forbidden = ['DB::', 'Eloquent', 'user_pass', 'Hash::', 'Gate::', 'fetch(', 'axios'];

        foreach ($files as $file) {
            if (! is_string($file)) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    basename($file)." contains '{$needle}'. Themes consume data, they do not fetch it.",
                );
            }
        }
    }
}
