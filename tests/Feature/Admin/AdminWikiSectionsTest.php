<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\WikiCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Naming the wiki's sections from the dashboard.
 *
 * Built against a temporary directory for the reason AdminWikiTest is: the
 * sections that ship with the panel are a starting point an operator deletes,
 * so asserting against them would be asserting about sample content.
 *
 * What these are really checking is the same thing its companion checks, one
 * level up: that the overlay is an overlay. A `_category.md` must still say
 * what it said after a section has been renamed in the browser, and reverting
 * must hand it back -- if a save ever wrote to disk, the revert test is the one
 * that would notice.
 */
final class AdminWikiSectionsTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/fluxcp-admin-sections-'.bin2hex(random_bytes(6));

        config([
            'wiki.enabled' => true,
            'wiki.path' => $this->root,
            'wiki.cache_seconds' => 0,
            'wiki.popular' => [],
            'wiki.rates' => [],
        ]);

        $this->page('getting-started/_category.md', [
            'title' => 'Getting started',
            'description' => 'From the file.',
            'icon' => 'play',
            'order' => '1',
        ]);

        $this->page('getting-started/welcome.md', [
            'title' => 'Welcome',
            'order' => '1',
        ], "Hello from the file.\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    private function admin(): Account
    {
        return Account::factory()->administrator()->create();
    }

    /*
    |--------------------------------------------------------------------------
    | Reaching it
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_section_editor_is_administrator_only(): void
    {
        $this->getJson('/api/admin/wiki/sections')->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->getJson('/api/admin/wiki/sections')->assertStatus(403);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->getJson('/api/admin/wiki/sections')->assertStatus(403);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/sections')->assertOk();
    }

    #[Test]
    public function naming_a_section_is_administrator_only(): void
    {
        $this->actingAs(Account::factory()->create())
            ->postJson('/api/admin/wiki/sections', [
                'slug' => 'getting-started',
                'title' => 'Hijacked',
            ])->assertStatus(403);

        $this->assertDatabaseCount('wiki_categories', 0);
    }

    /**
     * `sections` is the shape a page path has, and the page routes give it up.
     *
     * Without the reservation `admin/wiki/sections/getting-started` would reach
     * the page handler as the page `sections/getting-started`, which is a
     * different screen answering a different question.
     */
    #[Test]
    public function the_section_routes_win_over_the_page_routes(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/sections/getting-started')
            ->assertOk()
            ->assertJsonPath('data.slug', 'getting-started')
            // A page's payload has a body; a section's has a page count.
            ->assertJsonPath('data.pages', 1)
            ->assertJsonMissingPath('data.body');
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_the_sections_on_disk_as_coming_from_a_file(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/sections')
            ->assertOk()
            ->assertJsonPath('data.sections.0.slug', 'getting-started')
            ->assertJsonPath('data.sections.0.title', 'Getting started')
            ->assertJsonPath('data.sections.0.description', 'From the file.')
            ->assertJsonPath('data.sections.0.icon', 'play')
            ->assertJsonPath('data.sections.0.pages', 1)
            ->assertJsonPath('data.sections.0.source', 'file');
    }

    #[Test]
    public function the_editor_opens_a_file_backed_section_on_what_the_file_says(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/sections/getting-started')
            ->assertOk()
            ->assertJsonPath('data.title', 'Getting started')
            ->assertJsonPath('data.description', 'From the file.')
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonPath('data.source', 'file');
    }

    #[Test]
    public function a_slug_that_is_not_a_section_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/sections/nothing-here')
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Naming
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_name_is_laid_over_the_category_file_without_touching_it(): void
    {
        $file = $this->root.'/getting-started/_category.md';
        $before = (string) file_get_contents($file);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/sections', [
                'slug' => 'getting-started',
                'title' => 'Start here',
                'description' => 'Now from the database.',
                'icon' => 'map',
                'sort_order' => 3,
            ])
            ->assertStatus(201);

        // The public wiki serves the row...
        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.categories.0.title', 'Start here')
            ->assertJsonPath('data.categories.0.description', 'Now from the database.')
            ->assertJsonPath('data.categories.0.icon', 'map');

        // ...and the file is byte-for-byte what it was.
        $this->assertSame($before, (string) file_get_contents($file));
    }

    #[Test]
    public function a_named_section_is_listed_as_having_both_sources(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'getting-started',
            'title' => 'Start here',
        ])->assertStatus(201);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/sections')
            ->assertOk()
            ->assertJsonPath('data.sections.0.source', 'both');
    }

    /**
     * The whole point of the screen, from the side of the operator who needed
     * it: a section that exists only because a page was written into it used
     * to be stuck with a title derived from its own slug.
     */
    #[Test]
    public function a_section_with_no_directory_can_be_given_a_name(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'server-rules',
            'slug' => 'conduct',
            'title' => 'Conduct',
            'body' => 'Be decent.',
        ])->assertStatus(201);

        // Titled from the slug until somebody says otherwise.
        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.categories.1.slug', 'server-rules')
            ->assertJsonPath('data.categories.1.title', 'Server Rules');

        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'server-rules',
            'title' => 'Rules of the server',
            'icon' => 'shield',
        ])->assertStatus(201);

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.categories.1.title', 'Rules of the server')
            ->assertJsonPath('data.categories.1.icon', 'shield');
    }

    /**
     * A section named here with nothing in it yet is a row and nothing else.
     *
     * The wiki goes on dropping empty sections -- an empty card reads as a
     * broken page -- while the dashboard lists them, because laying out a guide
     * before writing it is a thing people do.
     */
    #[Test]
    public function a_section_with_no_pages_is_listed_here_but_not_on_the_wiki(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'events',
            'title' => 'Events',
        ])->assertStatus(201);

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.slug', 'getting-started');

        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/sections')
            ->assertOk()
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonPath('data.sections.1.slug', 'events')
            ->assertJsonPath('data.sections.1.pages', 0)
            ->assertJsonPath('data.sections.1.source', 'database');
    }

    #[Test]
    public function saving_a_section_twice_edits_the_one_row(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'getting-started',
            'title' => 'First go',
        ])->assertStatus(201);

        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'getting-started',
            'title' => 'Second go',
        ])->assertStatus(200);

        $this->assertDatabaseCount('wiki_categories', 1);
        $this->assertSame('Second go', WikiCategory::query()->findOrFail('getting-started')->title);
    }

    #[Test]
    public function the_order_a_section_is_given_decides_where_it_sorts(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'events',
            'slug' => 'calendar',
            'title' => 'Calendar',
            'body' => 'What is on.',
        ])->assertStatus(201);

        // `getting-started` declares order 1 in its file, so it leads.
        $this->getJson('/api/wiki')
            ->assertJsonPath('data.categories.0.slug', 'getting-started');

        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'events',
            'title' => 'Events',
            'sort_order' => 0,
        ])->assertStatus(201);

        $this->getJson('/api/wiki')
            ->assertJsonPath('data.categories.0.slug', 'events');
    }

    /*
    |--------------------------------------------------------------------------
    | A section's art
    |--------------------------------------------------------------------------
    */

    /**
     * The square an operator uploads is what a reader sees on the index.
     */
    #[Test]
    public function a_sections_image_reaches_the_wiki_index(): void
    {
        Storage::fake('public');

        config([
            'wiki.uploads.disk' => 'public',
            'wiki.uploads.section_image.directory' => 'wiki/sections',
            'wiki.uploads.section_image.width' => 120,
            'wiki.uploads.section_image.height' => 120,
        ]);

        $url = (string) $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/sections/image', [
                'image' => UploadedFile::fake()->image('tile.png', 120, 120),
            ])
            ->assertStatus(201)
            ->json('data.url');

        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'getting-started',
            'title' => 'Getting started',
            'image' => $url,
        ])->assertStatus(201);

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.categories.0.image', $url);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/sections/getting-started')
            ->assertOk()
            ->assertJsonPath('data.image', $url);
    }

    /**
     * A tile can only point at a square this panel stored.
     *
     * Otherwise the index -- the most-visited page the wiki has -- could be
     * made to load nine images from somewhere else, which hands that server
     * the IP address of every visitor and breaks the page when it goes down.
     */
    #[Test]
    public function it_refuses_an_image_it_did_not_store(): void
    {
        foreach ([
            'https://example.test/tile.webp',
            '/storage/wiki/sections/../../../.env',
            '/storage/wiki/9e0438c8724003ae5a03a7d74b4c00de.png',
            '/etc/passwd',
        ] as $url) {
            $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
                'slug' => 'events',
                'title' => 'Events',
                'image' => $url,
            ])->assertStatus(422)->assertJsonValidationErrors('image');
        }

        $this->assertDatabaseCount('wiki_categories', 0);
    }

    /**
     * A section with no art is the ordinary case, not a missing value: the
     * index draws its own placeholder.
     */
    #[Test]
    public function a_section_can_have_no_image(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'getting-started',
            'title' => 'Getting started',
        ])->assertStatus(201);

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.categories.0.image', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Reverting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function reverting_hands_the_category_file_back(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'getting-started',
            'title' => 'Start here',
            'description' => 'From the database.',
        ])->assertStatus(201);

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/wiki/sections/getting-started')
            ->assertOk()
            // What the section is now, which the dashboard cannot work out on
            // its own: the file's title is back.
            ->assertJsonPath('data.title', 'Getting started')
            ->assertJsonPath('data.pages', 1);

        $this->assertDatabaseCount('wiki_categories', 0);

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.categories.0.title', 'Getting started')
            ->assertJsonPath('data.categories.0.description', 'From the file.');
    }

    /**
     * Reverting a section leaves its pages alone.
     *
     * The two overlays are separate tables and separate screens, and this is
     * the assertion that says so: dropping the name does not touch anything
     * somebody wrote inside the section.
     */
    #[Test]
    public function reverting_a_section_does_not_remove_its_pages(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'events',
            'slug' => 'calendar',
            'title' => 'Calendar',
            'body' => 'What is on.',
        ])->assertStatus(201);

        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'events',
            'title' => 'Events',
        ])->assertStatus(201);

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/wiki/sections/events')
            ->assertOk()
            // Still a section, because its page is still in it -- titled from
            // its own slug again, the way a bare directory would be.
            ->assertJsonPath('data.title', 'Events')
            ->assertJsonPath('data.pages', 1);

        $this->getJson('/api/wiki/events/calendar')
            ->assertOk()
            ->assertJsonPath('data.title', 'Calendar');
    }

    #[Test]
    public function reverting_a_section_nothing_was_written_about_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/wiki/sections/getting-started')
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_a_slug_that_is_not_a_slug(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'Getting Started',
            'title' => 'Getting started',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function it_refuses_a_section_with_no_title(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'events',
            'title' => '',
        ])->assertStatus(422)->assertJsonValidationErrors('title');
    }

    /**
     * An icon is a name from a drawn set, and the rest are refused.
     *
     * Not fussiness: an unknown name renders as the fallback book, so a typo
     * accepted here would look to the operator like a save that went wrong.
     */
    #[Test]
    public function it_refuses_an_icon_that_is_not_one_of_the_drawn_ones(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki/sections', [
            'slug' => 'events',
            'title' => 'Events',
            'icon' => 'https://example.test/icon.png',
        ])->assertStatus(422)->assertJsonValidationErrors('icon');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, string>  $front
     */
    private function page(string $path, array $front = [], string $body = ''): void
    {
        $file = $this->root.'/'.$path;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }

        $block = '';

        foreach ($front as $key => $value) {
            $block .= $key.': '.$value."\n";
        }

        file_put_contents($file, $block === '' ? $body : "---\n".$block."---\n\n".$body);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;

            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
