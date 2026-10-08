<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Support\Content\FrontMatter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The wiki: a directory of Markdown read as a structured guide.
 *
 * One directory per section, one file per page, and the names on disk are the
 * URL. See config/wiki.php for why the content lives on a filesystem rather
 * than in a table, and docs/WIKI.md for how to write a page.
 *
 * ---------------------------------------------------------------------------
 * Nothing here builds a path out of a request
 * ---------------------------------------------------------------------------
 *
 * The obvious implementation of "serve /wiki/{path}" is to concatenate the
 * path onto the content directory and read the file. That is also how a
 * traversal gets served: `..%2f..%2f.env` is a path, and every mitigation for
 * it is a rule somebody has to keep remembering.
 *
 * So the lookup goes the other way round. The directory is scanned, which
 * produces the set of pages that exist and the file behind each one, and a
 * requested path is matched against that set. A path that is not a key is a
 * 404 before any file is opened, and no filename is ever derived from input.
 *
 * ---------------------------------------------------------------------------
 * What is cached and what is not
 * ---------------------------------------------------------------------------
 *
 * The scan -- names, titles, ordering, timestamps -- is cached for
 * wiki.cache_seconds, which defaults to zero because an operator fixing a
 * typo should see the fix. Page bodies are never cached: they are read when a
 * page is asked for, and rendering one costs less than storing every one of
 * them twice.
 */
final readonly class WikiLibrary
{
    public function __construct(
        private ConfigRepository $config,
        private CacheRepository $cache,
        private ContentRenderer $renderer,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('wiki.enabled', true);
    }

    /*
    |--------------------------------------------------------------------------
    | The index
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the landing page and the sidebar need, and no page bodies.
     *
     * @return array{
     *     categories: list<array<string, mixed>>,
     *     recent: list<array<string, mixed>>,
     *     popular: list<array<string, mixed>>,
     *     rates: list<array<string, mixed>>,
     *     totals: array{pages: int, categories: int},
     *     updated_at: string|null
     * }
     */
    public function index(): array
    {
        $categories = $this->scan();

        $pages = $this->flatten($categories);

        return [
            'categories' => array_map(
                fn (array $category): array => [
                    'slug' => $category['slug'],
                    'title' => $category['title'],
                    'description' => $category['description'],
                    'icon' => $category['icon'],
                    'count' => count($category['pages']),
                    'pages' => array_map($this->summarise(...), $category['pages']),
                ],
                $categories,
            ),
            'recent' => $this->recent($pages),
            'popular' => $this->popular($pages),
            'rates' => $this->rates(),
            'totals' => [
                'pages' => count($pages),
                'categories' => count($categories),
            ],
            // The newest edit anywhere in the wiki, for a "last reviewed" line.
            'updated_at' => $pages === [] ? null : max(array_column($pages, 'updated_at')),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | One page
    |--------------------------------------------------------------------------
    */

    /**
     * A page and its body, or null when no such page exists.
     *
     * @return array<string, mixed>|null
     */
    public function article(string $path): ?array
    {
        $categories = $this->scan();
        $pages = $this->flatten($categories);

        $position = null;

        foreach ($pages as $index => $page) {
            if ($page['path'] === $path) {
                $position = $index;

                break;
            }
        }

        if ($position === null) {
            return null;
        }

        $page = $pages[$position];

        /*
         * The file was there when the directory was scanned and may not be now
         * -- a deploy mid-request, or a cached scan outliving a deleted page.
         * Treated as a page that does not exist, which is what the visitor is
         * about to be told anyway.
         */
        $contents = @file_get_contents($page['file']);

        if ($contents === false) {
            return null;
        }

        $document = FrontMatter::parse($contents);

        [$html, $headings] = $this->render($document->body);

        $category = $this->categoryOf($categories, $page['category']);

        return [
            'path' => $page['path'],
            'title' => $page['title'],
            'summary' => $page['summary'],
            'updated_at' => $page['updated_at'],
            'reading_minutes' => $this->readingMinutes($document->body),
            'html' => $html,
            'headings' => $headings,
            'category' => $category === null ? null : [
                'slug' => $category['slug'],
                'title' => $category['title'],
                'icon' => $category['icon'],
            ],
            /*
             * Reading order across the whole wiki rather than within the
             * section, so following "next" from the last page of Getting
             * Started arrives at the first page of what comes after it
             * instead of at a dead end.
             */
            'previous' => $position > 0
                ? $this->reference($pages[$position - 1])
                : null,
            'next' => $position + 1 < count($pages)
                ? $this->reference($pages[$position + 1])
                : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    /**
     * Pages matching a term, best match first.
     *
     * Ranked by where the term was found rather than how often: a page whose
     * title is the question is the answer, however many times a longer page
     * mentions the same word in passing.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, ?int $limit = null): array
    {
        $term = trim($term);
        $minimum = max(1, (int) $this->config->get('wiki.search.minimum_length', 2));

        if (mb_strlen($term) < $minimum) {
            return [];
        }

        $limit = max(1, $limit ?? (int) $this->config->get('wiki.search.limit', 12));
        $needle = mb_strtolower($term);

        $matches = [];

        foreach ($this->flatten($this->scan()) as $page) {
            $body = $this->bodyOf($page['file']);

            $score = match (true) {
                str_contains(mb_strtolower($page['title']), $needle) => 3,
                str_contains(mb_strtolower((string) $page['summary']), $needle) => 2,
                str_contains(mb_strtolower($body), $needle) => 1,
                default => 0,
            };

            if ($score === 0) {
                continue;
            }

            $matches[] = [
                'path' => $page['path'],
                'title' => $page['title'],
                'category' => $page['category_title'],
                'snippet' => $this->snippet($page['summary'] ?? '', $body, $term),
                'score' => $score,
            ];
        }

        /*
         * Alphabetical inside a tier. Anything cleverer -- recency, length,
         * term frequency -- would be a ranking nobody can predict from the
         * outside, and a short list that is stable is easier to use than a
         * short list that reshuffles.
         */
        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: strcmp($a['title'], $b['title']));

        return array_map(
            static fn (array $match): array => array_diff_key($match, ['score' => null]),
            array_slice($matches, 0, $limit),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scanning the directory
    |--------------------------------------------------------------------------
    */

    /**
     * The sections and their pages, in the order they should be read.
     *
     * @return list<array<string, mixed>>
     */
    private function scan(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $seconds = max(0, (int) $this->config->get('wiki.cache_seconds', 0));

        if ($seconds === 0) {
            return $this->read();
        }

        $key = 'wiki:index:'.sha1($this->root());

        /** @var list<array<string, mixed>> $cached */
        $cached = $this->cache->remember($key, $seconds, fn (): array => $this->read());

        return $cached;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        $root = $this->root();

        if (! is_dir($root)) {
            return [];
        }

        $categories = [];

        foreach ($this->entries($root) as $name) {
            $directory = $root.DIRECTORY_SEPARATOR.$name;

            if (! is_dir($directory)) {
                continue;
            }

            $slug = $this->slug($name);

            if ($slug === null || isset($categories[$slug])) {
                continue;
            }

            $category = $this->category($slug, $name, $directory);

            // A section with nothing in it is a directory somebody made and
            // has not written yet. Drawn as an empty card it looks like a
            // broken page, so it is simply not a section yet.
            if ($category['pages'] !== []) {
                $categories[$slug] = $category;
            }
        }

        return $this->ordered(array_values($categories));
    }

    /**
     * One section: its own metadata, then its pages.
     *
     * @return array<string, mixed>
     */
    private function category(string $slug, string $name, string $directory): array
    {
        $meta = $this->metadata($directory.DIRECTORY_SEPARATOR.'_category.md');

        $title = $meta?->get('title') ?? Str::headline($name);

        $pages = [];

        foreach ($this->entries($directory) as $file) {
            if (! str_ends_with(strtolower($file), '.md') || str_starts_with($file, '_')) {
                continue;
            }

            $full = $directory.DIRECTORY_SEPARATOR.$file;

            if (! is_file($full)) {
                continue;
            }

            $base = substr($file, 0, -3);
            $pageSlug = $this->slug($base);

            if ($pageSlug === null || isset($pages[$pageSlug])) {
                continue;
            }

            $front = $this->metadata($full);

            $pages[$pageSlug] = [
                'path' => $slug.'/'.$pageSlug,
                'slug' => $pageSlug,
                'title' => $front?->get('title') ?? Str::headline($base),
                'summary' => $front?->get('summary'),
                'order' => $front?->integer('order'),
                'updated_at' => $this->updatedAt($front, $full),
                'file' => $full,
                'category' => $slug,
                'category_title' => $title,
            ];
        }

        return [
            'slug' => $slug,
            'title' => $title,
            'description' => $meta?->get('description'),
            'icon' => $meta?->get('icon'),
            'order' => $meta?->integer('order'),
            'pages' => $this->ordered(array_values($pages)),
        ];
    }

    /**
     * A file's front matter, or null when it cannot be read.
     */
    private function metadata(string $file): ?FrontMatter
    {
        if (! is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        return $contents === false ? null : FrontMatter::parse($contents);
    }

    /**
     * A directory's entries, sorted, with the dot files left out.
     *
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => ! str_starts_with($entry, '.'),
        ));
    }

    /**
     * Sort by the declared order, then alphabetically by title.
     *
     * A page without an `order` sorts after every page that has one, so
     * numbering the first three pages of a section is enough to pin them to
     * the top without having to number the rest.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function ordered(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $left = $a['order'] ?? PHP_INT_MAX;
            $right = $b['order'] ?? PHP_INT_MAX;

            return $left <=> $right ?: strcmp((string) $a['title'], (string) $b['title']);
        });

        return $items;
    }

    /**
     * The name a file or directory is reachable by.
     *
     * Derived rather than required, so `Creating an Account.md` is a page at
     * `creating-an-account` instead of a file the operator cannot see and
     * cannot explain. A name that slugs to nothing at all -- one made only of
     * punctuation -- has no URL that could be written down, and is skipped.
     */
    private function slug(string $name): ?string
    {
        $slug = Str::slug($name);

        return $slug === '' ? null : $slug;
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    */

    /**
     * The body as HTML, and the headings a reader can jump to.
     *
     * Anchors are added after rendering rather than by a Markdown extension,
     * because the renderer is shared with the news and the CMS pages and this
     * is the only place that wants them.
     *
     * Only h2 and h3 are anchored. The page's own title is drawn from the
     * front matter, so an h1 inside the body is a second title rather than a
     * section, and listing it in the contents would be listing the page
     * inside itself.
     *
     * @return array{0: string, 1: list<array{id: string, text: string, level: int}>}
     */
    private function render(string $body): array
    {
        $html = $this->renderer->toHtml($body);

        $headings = [];
        $used = [];

        $html = (string) preg_replace_callback(
            '#<h([23])>(.*?)</h\1>#s',
            function (array $match) use (&$headings, &$used): string {
                $level = (int) $match[1];
                $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                $id = Str::slug($text);

                if ($id === '') {
                    $id = 'section';
                }

                // Two sections can legitimately be called "Notes". The first
                // keeps the plain anchor and the rest are numbered, so every
                // link in the contents lands somewhere different.
                $used[$id] = ($used[$id] ?? 0) + 1;

                if ($used[$id] > 1) {
                    $id .= '-'.$used[$id];
                }

                $headings[] = ['id' => $id, 'text' => $text, 'level' => $level];

                return '<h'.$level.' id="'.e($id).'">'.$match[2].'</h'.$level.'>';
            },
            $html,
        );

        return [$html, $headings];
    }

    /**
     * Roughly how long the page takes to read.
     *
     * 200 words a minute, rounded up, never zero. A figure rather than a
     * promise: it is there so somebody can tell a paragraph from an essay
     * before they start.
     */
    private function readingMinutes(string $body): int
    {
        $words = str_word_count(strip_tags($this->renderer->toHtml($body)));

        return max(1, (int) ceil($words / 200));
    }

    /*
    |--------------------------------------------------------------------------
    | Shaping
    |--------------------------------------------------------------------------
    */

    /**
     * Every page, in reading order, with its section's order applied first.
     *
     * @param  list<array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    private function flatten(array $categories): array
    {
        $pages = [];

        foreach ($categories as $category) {
            foreach ($category['pages'] as $page) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @return array<string, mixed>|null
     */
    private function categoryOf(array $categories, string $slug): ?array
    {
        foreach ($categories as $category) {
            if ($category['slug'] === $slug) {
                return $category;
            }
        }

        return null;
    }

    /**
     * A page as the index lists it: everything but the file behind it.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function summarise(array $page): array
    {
        return [
            'path' => $page['path'],
            'title' => $page['title'],
            'summary' => $page['summary'],
            'updated_at' => $page['updated_at'],
        ];
    }

    /**
     * The smallest shape that is still a link.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function reference(array $page): array
    {
        return [
            'path' => $page['path'],
            'title' => $page['title'],
            'category' => $page['category_title'],
        ];
    }

    /**
     * The most recently edited pages, newest first.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function recent(array $pages): array
    {
        usort($pages, static fn (array $a, array $b): int => strcmp(
            (string) $b['updated_at'],
            (string) $a['updated_at'],
        ));

        $limit = max(1, (int) $this->config->get('wiki.recent_limit', 6));

        return array_map(
            fn (array $page): array => [
                ...$this->reference($page),
                'updated_at' => $page['updated_at'],
            ],
            array_slice($pages, 0, $limit),
        );
    }

    /**
     * The configured shortcuts, in the order they were configured.
     *
     * A path that no longer resolves is dropped: a chip that 404s is worse
     * than one fewer chip, and pruning a page should not require remembering
     * to prune the configuration too.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function popular(array $pages): array
    {
        $byPath = array_column($pages, null, 'path');

        $shortcuts = [];

        foreach ((array) $this->config->get('wiki.popular', []) as $path) {
            $page = $byPath[trim((string) $path, '/')] ?? null;

            if ($page !== null) {
                $shortcuts[] = $this->reference($page);
            }
        }

        return $shortcuts;
    }

    /**
     * The rates panel, with the entries that say nothing left out.
     *
     * @return list<array<string, mixed>>
     */
    private function rates(): array
    {
        $rates = [];

        foreach ((array) $this->config->get('wiki.rates', []) as $rate) {
            if (! is_array($rate)) {
                continue;
            }

            $label = trim((string) ($rate['label'] ?? ''));
            $value = trim((string) ($rate['value'] ?? ''));

            if ($label === '' || $value === '') {
                continue;
            }

            $note = trim((string) ($rate['note'] ?? ''));

            $rates[] = [
                'label' => $label,
                'value' => $value,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $rates;
    }

    /**
     * When the page was last changed.
     *
     * The front matter wins when it carries a readable date, because an
     * operator saying "this was reviewed in March" is making a claim about
     * the content; the file's own timestamp is the fallback, and it is the
     * better default -- a checkout rewrites it, but nobody has to remember to
     * update it.
     */
    private function updatedAt(?FrontMatter $front, string $file): ?string
    {
        $declared = $front?->get('updated');

        if ($declared !== null) {
            try {
                return Carbon::parse($declared)->toIso8601String();
            } catch (\Throwable) {
                // An unreadable date falls through to the timestamp rather
                // than being shown as written: this value is compared and
                // sorted, not only displayed.
            }
        }

        $stamp = @filemtime($file);

        return $stamp === false ? null : Carbon::createFromTimestamp($stamp)->toIso8601String();
    }

    /**
     * A page's body as plain text, for searching.
     */
    private function bodyOf(string $file): string
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return '';
        }

        return FrontMatter::parse($contents)->body;
    }

    /**
     * The line to show under a search hit.
     *
     * The summary when the page has one, because it was written to describe
     * the page. Otherwise the text around the match, which at least shows why
     * the page came back.
     */
    private function snippet(string $summary, string $body, string $term): string
    {
        if (trim($summary) !== '') {
            return $summary;
        }

        $length = max(40, (int) $this->config->get('wiki.search.snippet_length', 160));
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->renderer->toHtml($body))));

        $at = mb_stripos($text, $term);

        if ($at === false) {
            return Str::limit($text, $length);
        }

        // Start a little before the match so it is read in context rather
        // than as the first word of the line.
        $from = max(0, $at - 60);
        $excerpt = mb_substr($text, $from, $length);

        return ($from > 0 ? '…' : '').trim($excerpt).'…';
    }

    /**
     * The content directory, as an absolute path.
     */
    private function root(): string
    {
        $path = (string) $this->config->get('wiki.path', 'resources/wiki');

        // Relative paths are relative to the project, not to whatever
        // directory the process happens to have been started in.
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            ? rtrim($path, '/\\')
            : base_path(rtrim($path, '/\\'));
    }
}
