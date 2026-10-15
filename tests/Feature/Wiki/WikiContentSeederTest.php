<?php

declare(strict_types=1);

namespace Tests\Feature\Wiki;

use App\Models\WikiPage;
use Database\Seeders\WikiContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The starter wiki: nine sections on disk, one page each in the database.
 *
 * The halves are easy to break apart without noticing, which is what most of
 * these check. A section renamed in resources/wiki and not in the seeder
 * leaves a page orphaned into a slug-titled section of its own; a seeder run
 * twice must not overwrite what an operator has since written.
 */
final class WikiContentSeederTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    /** In the order they are meant to read on the wiki's front page. */
    private const SECTIONS = [
        'getting-started',
        'website-features',
        'game-access',
        'server-guide',
        'events',
        'game-features',
        'modifications',
        'other-informations',
        'server-updates',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wiki.enabled' => true,
            'wiki.cache_seconds' => 0,
        ]);
    }

    #[Test]
    public function it_seeds_one_page_for_every_section(): void
    {
        $this->seed(WikiContentSeeder::class);

        $this->assertSame(
            count(self::SECTIONS),
            WikiPage::query()->count(),
            'the seeder should write exactly one page per section',
        );

        foreach (self::SECTIONS as $section) {
            $page = WikiPage::query()->find($section.'/overview');

            $this->assertNotNull($page, "{$section} was not seeded");
            $this->assertSame($section, $page->category);
            $this->assertNotSame('', (string) $page->body, "{$section} was seeded empty");
            $this->assertFalse($page->hidden);
        }
    }

    /**
     * Every seeded page lands in a section that exists on disk.
     *
     * A page whose category has no directory still renders, but as a section
     * of its own titled from the slug, with no icon and sorted last -- which
     * looks like a bug and is the failure a rename would cause.
     */
    #[Test]
    public function every_seeded_page_has_a_section_on_disk(): void
    {
        $root = base_path((string) config('wiki.path'));

        foreach (self::SECTIONS as $section) {
            $this->assertFileExists($root.'/'.$section.'/_category.md');
        }
    }

    /** The order asked for, not the alphabetical order a slug would give. */
    #[Test]
    public function the_sections_read_in_the_configured_order(): void
    {
        $this->seed(WikiContentSeeder::class);

        $slugs = collect($this->getJson('/api/wiki')->json('data.categories'))
            ->pluck('slug')
            ->all();

        $this->assertSame(self::SECTIONS, $slugs);
    }

    /** Each section carries the metadata only `_category.md` can supply. */
    #[Test]
    public function every_section_has_a_title_an_icon_and_a_description(): void
    {
        $this->seed(WikiContentSeeder::class);

        foreach ($this->getJson('/api/wiki')->json('data.categories') as $category) {
            $this->assertNotSame('', (string) $category['title']);
            $this->assertNotNull($category['icon'], "{$category['slug']} has no icon");
            $this->assertNotNull($category['description'], "{$category['slug']} has no description");
        }
    }

    /**
     * A second run restores what was deleted and leaves edits alone.
     *
     * The wrong way round would eat an operator's writing the first time
     * somebody re-seeded, so this is the behaviour worth pinning.
     */
    #[Test]
    public function running_it_again_does_not_overwrite_an_edited_page(): void
    {
        $this->seed(WikiContentSeeder::class);

        WikiPage::query()->whereKey('events/overview')->update([
            'title' => 'Our events',
            'body' => 'Written by the operator.',
        ]);

        WikiPage::query()->whereKey('server-updates/overview')->delete();

        $this->seed(WikiContentSeeder::class);

        $edited = WikiPage::query()->find('events/overview');

        $this->assertSame('Our events', $edited?->title);
        $this->assertSame('Written by the operator.', $edited?->body);

        $this->assertNotNull(
            WikiPage::query()->find('server-updates/overview'),
            'a deleted page should come back',
        );
    }

    /**
     * Every page the seeder writes parses and renders.
     *
     * Moved here from Tests\Feature\Api\WikiTest when the starter content
     * moved into the database: it is the same guard, that a broken page in
     * shipped content fails here rather than on somebody's front page.
     */
    #[Test]
    public function it_renders_every_page_it_seeds(): void
    {
        $this->seed(WikiContentSeeder::class);

        $index = $this->getJson('/api/wiki')->assertOk()->json('data');

        $this->assertSame(count(self::SECTIONS), $index['totals']['pages']);

        foreach ($index['categories'] as $category) {
            foreach ($category['pages'] as $page) {
                $html = $this->getJson('/api/wiki/'.$page['path'])
                    ->assertOk()
                    ->json('data.html');

                $this->assertNotSame('', trim((string) $html), $page['path']);
            }
        }
    }

    /** The chips above the search box must point at pages that exist. */
    #[Test]
    public function the_popular_shortcuts_resolve(): void
    {
        $this->seed(WikiContentSeeder::class);

        $configured = (array) config('wiki.popular');

        $this->assertNotSame([], $configured, 'nothing is offered as a shortcut');

        $this->assertCount(
            count($configured),
            $this->getJson('/api/wiki')->json('data.popular'),
            'a shortcut was dropped, so it points at a page that is not there',
        );
    }

    /** None of the pages the panel used to ship are still being served. */
    #[Test]
    public function the_premade_pages_are_gone(): void
    {
        $this->seed(WikiContentSeeder::class);

        $paths = collect($this->getJson('/api/wiki')->json('data.categories'))
            ->flatMap(fn (array $category): array => array_column($category['pages'], 'path'))
            ->all();

        foreach ([
            'getting-started/welcome',
            'getting-started/creating-an-account',
            'getting-started/downloading-the-client',
            'the-website/your-account',
            'help/frequently-asked-questions',
        ] as $retired) {
            $this->assertNotContains($retired, $paths);
        }
    }
}
