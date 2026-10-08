<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wiki: a directory of Markdown served as a guide.
 *
 * Most of these work against a directory built in setUp rather than against
 * the pages that ship with the panel, because those are a starting point an
 * operator is expected to delete. The one test that does read them asserts
 * only that they parse -- it is there so a broken front-matter block in
 * shipped content fails here rather than on somebody's front page.
 */
final class WikiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/fluxcp-wiki-'.bin2hex(random_bytes(6));

        config([
            'wiki.enabled' => true,
            'wiki.path' => $this->root,
            // Explicit, so a cached scan from an earlier test cannot answer
            // for a directory this one has just written.
            'wiki.cache_seconds' => 0,
            'wiki.popular' => [],
            'wiki.rates' => [],
        ]);

        $this->page('getting-started/_category.md', [
            'title' => 'Getting started',
            'description' => 'The first things to do.',
            'icon' => 'play',
            'order' => '1',
        ]);

        $this->page('getting-started/welcome.md', [
            'title' => 'Welcome',
            'summary' => 'Start here.',
            'order' => '1',
        ], "Hello.\n\n## First section\n\nA paragraph about zeny.\n");

        $this->page('getting-started/second-steps.md', [
            'title' => 'Second steps',
            'order' => '2',
        ], 'More.');

        $this->page('help/_category.md', [
            'title' => 'Help',
            'order' => '2',
        ]);

        $this->page('help/faq.md', [], 'Answers.');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | The index
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_every_section_and_the_pages_in_it(): void
    {
        $response = $this->getJson('/api/wiki')->assertOk();

        $response->assertJsonPath('data.totals.categories', 2)
            ->assertJsonPath('data.totals.pages', 3)
            ->assertJsonPath('data.categories.0.slug', 'getting-started')
            ->assertJsonPath('data.categories.0.title', 'Getting started')
            ->assertJsonPath('data.categories.0.description', 'The first things to do.')
            ->assertJsonPath('data.categories.0.icon', 'play')
            ->assertJsonPath('data.categories.0.count', 2)
            ->assertJsonPath('data.categories.0.pages.0.path', 'getting-started/welcome')
            ->assertJsonPath('data.categories.0.pages.0.summary', 'Start here.');
    }

    #[Test]
    public function it_orders_sections_and_pages_by_what_they_declare(): void
    {
        // Declared last, named first alphabetically: the order wins.
        $this->page('aardvark/_category.md', ['title' => 'Aardvark', 'order' => '9']);
        $this->page('aardvark/only.md', ['title' => 'Only']);

        $response = $this->getJson('/api/wiki')->assertOk();

        $response->assertJsonPath('data.categories.0.slug', 'getting-started')
            ->assertJsonPath('data.categories.1.slug', 'help')
            ->assertJsonPath('data.categories.2.slug', 'aardvark')
            ->assertJsonPath('data.categories.0.pages.1.title', 'Second steps');
    }

    #[Test]
    public function a_page_without_a_title_is_titled_from_its_filename(): void
    {
        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.categories.1.pages.0.title', 'Faq');
    }

    #[Test]
    public function a_section_with_no_pages_is_not_a_section(): void
    {
        mkdir($this->root.'/drafts', 0o755, true);
        file_put_contents($this->root.'/drafts/_category.md', "---\ntitle: Drafts\n---\n");

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.totals.categories', 2)
            ->assertJsonMissing(['slug' => 'drafts']);
    }

    #[Test]
    public function a_shortcut_to_a_page_that_no_longer_exists_is_dropped(): void
    {
        config(['wiki.popular' => ['getting-started/welcome', 'help/deleted']]);

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonCount(1, 'data.popular')
            ->assertJsonPath('data.popular.0.path', 'getting-started/welcome');
    }

    #[Test]
    public function a_rate_with_no_figure_is_left_out(): void
    {
        config(['wiki.rates' => [
            ['label' => 'Base EXP', 'value' => '15x', 'note' => 'Capped at 99'],
            ['label' => 'Job EXP', 'value' => ''],
            ['label' => '', 'value' => '3x'],
        ]]);

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonCount(1, 'data.rates')
            ->assertJsonPath('data.rates.0.value', '15x')
            ->assertJsonPath('data.rates.0.note', 'Capped at 99');
    }

    /*
    |--------------------------------------------------------------------------
    | One page
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_serves_a_page_rendered_from_markdown(): void
    {
        $response = $this->getJson('/api/wiki/getting-started/welcome')->assertOk();

        $response->assertJsonPath('data.title', 'Welcome')
            ->assertJsonPath('data.category.title', 'Getting started');

        $this->assertStringContainsString('<p>Hello.</p>', $response->json('data.html'));
    }

    #[Test]
    public function it_anchors_the_headings_and_lists_them(): void
    {
        $response = $this->getJson('/api/wiki/getting-started/welcome')->assertOk();

        $response->assertJsonPath('data.headings.0.id', 'first-section')
            ->assertJsonPath('data.headings.0.text', 'First section')
            ->assertJsonPath('data.headings.0.level', 2);

        $this->assertStringContainsString(
            '<h2 id="first-section">First section</h2>',
            $response->json('data.html'),
        );
    }

    /**
     * The D20 rule, applied to a third content source.
     *
     * Someone who can write to the content directory can usually write to the
     * templates as well, so this is not a privilege boundary -- it is
     * uniformity. A paragraph must not change what it is allowed to do
     * because it was moved from the CMS into a file.
     */
    #[Test]
    public function it_strips_raw_html_from_a_page_body(): void
    {
        $this->page('help/dangerous.md', ['title' => 'Dangerous'], "<script>alert(1)</script>\n\nAfter.");

        $html = $this->getJson('/api/wiki/help/dangerous')->assertOk()->json('data.html');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('After.', $html);
    }

    #[Test]
    public function it_refuses_a_javascript_url_in_a_link(): void
    {
        $this->page('help/link.md', ['title' => 'Link'], '[press me](javascript:alert(1))');

        $html = $this->getJson('/api/wiki/help/link')->assertOk()->json('data.html');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function it_links_a_page_to_the_ones_either_side_of_it(): void
    {
        $this->getJson('/api/wiki/getting-started/welcome')
            ->assertOk()
            ->assertJsonPath('data.previous', null)
            ->assertJsonPath('data.next.path', 'getting-started/second-steps');

        // Reading order runs across sections, not only within one.
        $this->getJson('/api/wiki/getting-started/second-steps')
            ->assertOk()
            ->assertJsonPath('data.next.path', 'help/faq');
    }

    #[Test]
    public function it_answers_404_for_a_page_that_does_not_exist(): void
    {
        $this->getJson('/api/wiki/getting-started/nothing-here')->assertNotFound();
    }

    /**
     * No filename is ever built from the request -- the path is matched
     * against the pages found by scanning -- and the route constraint refuses
     * anything that is not two plain slugs before that even runs.
     */
    #[Test]
    public function it_refuses_a_path_that_tries_to_leave_the_content_directory(): void
    {
        foreach ([
            '/api/wiki/../.env',
            '/api/wiki/%2e%2e%2f%2e%2e%2f.env',
            '/api/wiki/getting-started/../../../.env',
            '/api/wiki/getting-started/_category',
        ] as $path) {
            $this->getJson($path)->assertNotFound();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_searches_titles_summaries_and_bodies(): void
    {
        $response = $this->getJson('/api/wiki/search?q=zeny')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.path', 'getting-started/welcome')
            ->assertJsonPath('meta.term', 'zeny');
    }

    #[Test]
    public function a_title_match_outranks_a_body_match(): void
    {
        $this->page('help/mentions.md', ['title' => 'Mentions'], 'This one only mentions welcome in passing.');

        $this->getJson('/api/wiki/search?q=welcome')
            ->assertOk()
            ->assertJsonPath('data.0.path', 'getting-started/welcome');
    }

    #[Test]
    public function it_does_not_search_on_a_term_too_short_to_mean_anything(): void
    {
        $this->getJson('/api/wiki/search?q=z')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Turned off
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_serves_nothing_at_all_when_the_operator_has_turned_it_off(): void
    {
        config(['wiki.enabled' => false]);

        $this->getJson('/api/wiki')->assertNotFound();
        $this->getJson('/api/wiki/getting-started/welcome')->assertNotFound();
        $this->getJson('/api/wiki/search?q=zeny')->assertNotFound();
    }

    #[Test]
    public function a_content_directory_that_is_not_there_is_an_empty_wiki_rather_than_an_error(): void
    {
        config(['wiki.path' => $this->root.'/nowhere']);

        $this->getJson('/api/wiki')
            ->assertOk()
            ->assertJsonPath('data.totals.pages', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | The pages that ship with the panel
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_starter_pages_parse_and_render(): void
    {
        config(['wiki.path' => 'resources/wiki']);

        $index = $this->getJson('/api/wiki')->assertOk()->json('data');

        $this->assertGreaterThan(0, $index['totals']['pages']);

        foreach ($index['categories'] as $category) {
            $this->assertNotSame('', trim((string) $category['title']));

            foreach ($category['pages'] as $page) {
                $article = $this->getJson('/api/wiki/'.$page['path'])
                    ->assertOk()
                    ->json('data');

                $this->assertNotSame('', trim((string) $article['html']), $page['path']);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Write a page, front matter first.
     *
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

        file_put_contents(
            $file,
            $block === '' ? $body : "---\n".$block."---\n\n".$body,
        );
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $full = $path.'/'.$entry;

            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }

        rmdir($path);
    }
}
