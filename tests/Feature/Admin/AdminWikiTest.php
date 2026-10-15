<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Editing the wiki from the dashboard.
 *
 * Built against a temporary directory for the same reason WikiTest is: the
 * pages that ship with the panel are a starting point an operator deletes, so
 * asserting against them would be asserting about sample content.
 *
 * What these are really checking is that the overlay is an overlay. A file
 * must still be a file after it has been edited, and reverting must put the
 * reader back on it -- if an edit ever wrote to disk, the revert tests are
 * the ones that would notice.
 */
final class AdminWikiTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/fluxcp-admin-wiki-'.bin2hex(random_bytes(6));

        config([
            'wiki.enabled' => true,
            'wiki.path' => $this->root,
            'wiki.cache_seconds' => 0,
            'wiki.popular' => [],
            'wiki.rates' => [],
        ]);

        $this->page('getting-started/_category.md', [
            'title' => 'Getting started',
            'order' => '1',
        ]);

        $this->page('getting-started/welcome.md', [
            'title' => 'Welcome',
            'summary' => 'Start here.',
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
    public function the_wiki_editor_is_administrator_only(): void
    {
        $this->getJson('/api/admin/wiki')->assertStatus(401);

        $this->actingAs(Account::factory()->create())
            ->getJson('/api/admin/wiki')->assertStatus(403);

        $this->actingAs(Account::factory()->seniorGameMaster()->create())
            ->getJson('/api/admin/wiki')->assertStatus(403);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki')->assertOk();
    }

    #[Test]
    public function writing_is_administrator_only(): void
    {
        $this->actingAs(Account::factory()->create())
            ->postJson('/api/admin/wiki', [
                'category' => 'getting-started',
                'slug' => 'welcome',
                'title' => 'Hijacked',
                'body' => 'Nope.',
            ])->assertStatus(403);

        $this->assertDatabaseCount('wiki_pages', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_the_pages_on_disk_as_coming_from_a_file(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki')
            ->assertOk()
            ->assertJsonPath('data.sections.0.slug', 'getting-started')
            ->assertJsonPath('data.sections.0.pages.0.path', 'getting-started/welcome')
            ->assertJsonPath('data.sections.0.pages.0.source', 'file');
    }

    #[Test]
    public function the_editor_opens_a_file_backed_page_on_what_the_file_says(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/getting-started/welcome')
            ->assertOk()
            ->assertJsonPath('data.title', 'Welcome')
            ->assertJsonPath('data.source', 'file')
            // Trimmed, because that is what FrontMatter::parse hands back; the
            // editor opens on the body, not on the blank line after it.
            ->assertJsonPath('data.body', 'Hello from the file.');
    }

    #[Test]
    public function a_path_that_is_not_a_page_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki/getting-started/nothing-here')
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Editing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_edit_is_laid_over_the_file_without_touching_it(): void
    {
        $file = $this->root.'/getting-started/welcome.md';
        $before = (string) file_get_contents($file);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki', [
                'category' => 'getting-started',
                'slug' => 'welcome',
                'title' => 'Welcome, edited',
                'summary' => 'Now from the database.',
                'sort_order' => 1,
                'body' => 'Hello from the dashboard.',
            ])
            ->assertStatus(201);

        // The public page serves the edit...
        $this->getJson('/api/wiki/getting-started/welcome')
            ->assertOk()
            ->assertJsonPath('data.title', 'Welcome, edited')
            ->assertJsonPath('data.source', 'database');

        // ...and the file is byte-for-byte what it was.
        $this->assertSame($before, (string) file_get_contents($file));
    }

    #[Test]
    public function an_edited_page_is_listed_as_having_both_sources(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'getting-started',
            'slug' => 'welcome',
            'title' => 'Welcome, edited',
            'body' => 'Hello from the dashboard.',
        ])->assertStatus(201);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki')
            ->assertOk()
            ->assertJsonPath('data.sections.0.pages.0.source', 'both');
    }

    #[Test]
    public function a_page_can_be_written_that_has_no_file_at_all(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'getting-started',
            'slug' => 'brand-new',
            'title' => 'Brand new',
            'body' => 'Written in the browser.',
        ])->assertStatus(201);

        $this->getJson('/api/wiki/getting-started/brand-new')
            ->assertOk()
            ->assertJsonPath('data.title', 'Brand new')
            ->assertJsonPath('data.source', 'database');
    }

    #[Test]
    public function a_page_in_a_section_that_has_no_directory_creates_the_section(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'server-rules',
            'slug' => 'conduct',
            'title' => 'Conduct',
            'body' => 'Be kind.',
        ])->assertStatus(201);

        $response = $this->getJson('/api/wiki')->assertOk();

        $slugs = array_column((array) $response->json('data.categories'), 'slug');

        $this->assertContains('server-rules', $slugs);

        // Titled from the slug, exactly as a directory with no _category.md is.
        $index = array_search('server-rules', $slugs, strict: true);
        $this->assertSame('Server Rules', $response->json("data.categories.{$index}.title"));
    }

    #[Test]
    public function saving_again_updates_rather_than_duplicating(): void
    {
        $admin = $this->admin();

        $payload = [
            'category' => 'getting-started',
            'slug' => 'welcome',
            'title' => 'First',
            'body' => 'One.',
        ];

        $this->actingAs($admin)->postJson('/api/admin/wiki', $payload)->assertStatus(201);

        $this->actingAs($admin)
            ->postJson('/api/admin/wiki', [...$payload, 'title' => 'Second'])
            ->assertStatus(200);

        $this->assertDatabaseCount('wiki_pages', 1);
        $this->assertSame('Second', WikiPage::query()->firstOrFail()->title);
    }

    #[Test]
    public function the_body_is_stored_as_markdown_and_rendered_on_the_way_out(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'getting-started',
            'slug' => 'welcome',
            'title' => 'Welcome',
            'body' => "## A heading\n\n<script>alert(1)</script>\n",
        ])->assertStatus(201);

        // Stored exactly as typed, so the editor reopens on what was written.
        $this->assertStringContainsString(
            '<script>',
            (string) WikiPage::query()->firstOrFail()->body,
        );

        $html = (string) $this->getJson('/api/wiki/getting-started/welcome')
            ->assertOk()
            ->json('data.html');

        // ...and the raw HTML is gone by the time a reader sees it.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('A heading', $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Removing and reverting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function removing_a_file_backed_page_hides_it_without_deleting_the_file(): void
    {
        $file = $this->root.'/getting-started/welcome.md';

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/wiki/getting-started/welcome')
            ->assertOk();

        $this->getJson('/api/wiki/getting-started/welcome')->assertStatus(404);

        $this->assertFileExists($file);
        $this->assertTrue(WikiPage::query()->firstOrFail()->hidden);
    }

    #[Test]
    public function a_removed_page_is_still_listed_so_it_can_be_brought_back(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/wiki/getting-started/welcome')->assertOk();

        $this->actingAs($this->admin())
            ->getJson('/api/admin/wiki')
            ->assertOk()
            ->assertJsonPath('data.hidden.0.path', 'getting-started/welcome');
    }

    #[Test]
    public function reverting_puts_the_file_back_in_front_of_readers(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/wiki', [
            'category' => 'getting-started',
            'slug' => 'welcome',
            'title' => 'Welcome, edited',
            'body' => 'Hello from the dashboard.',
        ])->assertStatus(201);

        $this->actingAs($admin)
            ->postJson('/api/admin/wiki/getting-started/welcome/revert')
            ->assertOk();

        $this->assertDatabaseCount('wiki_pages', 0);

        $this->getJson('/api/wiki/getting-started/welcome')
            ->assertOk()
            ->assertJsonPath('data.title', 'Welcome')
            ->assertJsonPath('data.source', 'file');
    }

    #[Test]
    public function reverting_a_page_that_only_ever_lived_in_the_database_removes_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/wiki', [
            'category' => 'getting-started',
            'slug' => 'brand-new',
            'title' => 'Brand new',
            'body' => 'Written in the browser.',
        ])->assertStatus(201);

        $this->actingAs($admin)
            ->postJson('/api/admin/wiki/getting-started/brand-new/revert')
            ->assertOk();

        $this->getJson('/api/wiki/getting-started/brand-new')->assertStatus(404);
    }

    #[Test]
    public function saving_a_removed_page_brings_it_back(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->deleteJson('/api/admin/wiki/getting-started/welcome')->assertOk();

        $this->actingAs($admin)->postJson('/api/admin/wiki', [
            'category' => 'getting-started',
            'slug' => 'welcome',
            'title' => 'Back again',
            'body' => 'Returned.',
        ])->assertOk();

        $this->getJson('/api/wiki/getting-started/welcome')
            ->assertOk()
            ->assertJsonPath('data.title', 'Back again');
    }

    #[Test]
    public function reverting_a_page_with_nothing_to_revert_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/wiki/getting-started/welcome/revert')
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_a_slug_that_would_not_survive_the_url(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'getting-started',
            'slug' => 'Not A Slug',
            'title' => 'Nope',
            'body' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function it_refuses_a_page_with_no_body(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/wiki', [
            'category' => 'getting-started',
            'slug' => 'empty',
            'title' => 'Empty',
            'body' => '',
        ])->assertStatus(422)->assertJsonValidationErrors('body');
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
