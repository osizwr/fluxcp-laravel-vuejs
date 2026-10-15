<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\SaveWikiCategoryRequest;
use App\Models\Account;
use App\Models\WikiCategory;
use App\Services\Content\WikiCategoryOverrides;
use App\Services\Content\WikiLibrary;
use Illuminate\Http\JsonResponse;

/**
 * The guide's sections, edited from the dashboard.
 *
 * The companion to WikiPageController, and the same bargain: nothing here
 * writes to the filesystem. A section is a directory with a `_category.md` in
 * it, and that file keeps saying what it said -- a row of `wiki_categories` is
 * laid over it, and dropping the row hands the file back.
 *
 * What this adds for an operator with nothing but a browser is the sentence
 * they could not write before. Naming a section nobody had used in the page
 * editor already created it, but it then appeared on the wiki titled from its
 * own slug, with no description and no icon, and there was no screen that
 * would let them fix that.
 *
 * Each section is one of three things, and the screen says which:
 *
 *   file       a directory in version control, untouched by this dashboard
 *   database   a section named here that has no directory behind it
 *   both       a directory with an edit laid over it, which can be reverted
 */
final class WikiCategoryController
{
    public function __construct(
        private readonly WikiLibrary $wiki,
        private readonly WikiCategoryOverrides $overrides,
    ) {}

    /**
     * Every section the wiki has, plus the ones waiting for a first page.
     *
     * Built from the library's own scan rather than from the table, so the
     * listing is the wiki as a reader sees it -- directories included -- with
     * one addition the reader does not get: a section written here that has no
     * page in it yet. The wiki drops those, since a section with nothing in it
     * would draw as an empty card, but the dashboard has to show them or
     * writing a section and then writing its pages could not be two visits.
     */
    public function index(): JsonResponse
    {
        $sections = [];

        foreach ($this->wiki->sections() as $slug => $section) {
            $sections[$slug] = [
                'slug' => $slug,
                'title' => $section['title'],
                'description' => $section['description'],
                'icon' => $section['icon'],
                'image' => $section['image'],
                'sort_order' => $section['order'],
                'pages' => $section['count'],
                'source' => $section['directory'] === null
                    ? 'database'
                    : ($this->overrides->find($slug) === null ? 'file' : 'both'),
            ];
        }

        foreach ($this->overrides->all() as $slug => $override) {
            if (isset($sections[$slug])) {
                continue;
            }

            $sections[$slug] = [
                'slug' => $slug,
                'title' => $override->title,
                'description' => $override->description,
                'icon' => $override->icon,
                'image' => $override->image,
                'sort_order' => $override->sort_order,
                'pages' => 0,
                'source' => 'database',
            ];
        }

        /*
         * The reading order the wiki itself uses: declared order first, then
         * alphabetically. The empty sections are sorted in among the rest
         * rather than appended, because they are where they will be once
         * somebody writes their first page.
         */
        $sections = array_values($sections);

        usort($sections, static function (array $a, array $b): int {
            $left = $a['sort_order'] ?? PHP_INT_MAX;
            $right = $b['sort_order'] ?? PHP_INT_MAX;

            return $left <=> $right ?: strcmp($a['title'], $b['title']);
        });

        return response()->json(['data' => ['sections' => $sections]]);
    }

    /**
     * One section, for the editor.
     *
     * An edited section is served from the row. One that has only a directory
     * is served from the scan, so opening it starts from what is actually
     * published rather than from an empty form.
     */
    public function show(string $slug): JsonResponse
    {
        $override = $this->overrides->find($slug);
        $section = $this->wiki->section($slug);

        abort_if($override === null && $section === null, 404, 'No such wiki section.');

        if ($override !== null) {
            return response()->json(['data' => [
                'slug' => $override->slug,
                'title' => $override->title,
                'description' => $override->description,
                'icon' => $override->icon,
                'image' => $override->image,
                'sort_order' => $override->sort_order,
                'pages' => $section['count'] ?? 0,
                'source' => $section === null || $section['directory'] === null
                    ? 'database'
                    : 'both',
            ]]);
        }

        return response()->json(['data' => [
            'slug' => $slug,
            'title' => $section['title'],
            'description' => $section['description'],
            'icon' => $section['icon'],
            'image' => $section['image'],
            'sort_order' => $section['order'],
            'pages' => $section['count'],
            'source' => $section['directory'] === null ? 'database' : 'file',
        ]]);
    }

    /**
     * Name a section, or save a change to one that is already named.
     *
     * One action for both, as the page editor has one, because the difference
     * is not one an operator is aware of: describing a section that has only
     * ever been a directory creates the first row for it, and that is not a
     * different thing from changing the description again tomorrow.
     *
     * A section named here and never given a page is a row and nothing else.
     * That is allowed on purpose -- it is how somebody lays out a guide before
     * writing it -- and the dashboard marks those as not yet on the wiki.
     */
    public function store(SaveWikiCategoryRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $account = $request->user();

        $section = WikiCategory::query()->updateOrCreate(['slug' => $data['slug']], [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'image' => $data['image'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
            'updated_by' => $account instanceof Account ? $account->account_id : null,
        ]);

        $this->invalidate();

        return response()->json([
            'message' => 'The section has been saved.',
            'data' => ['slug' => $section->slug],
        ], $section->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Drop the row, so the directory on disk takes over again.
     *
     * The only verb this table has, and it covers both of the things an
     * operator means by "remove this section":
     *
     *   a section with a directory  goes back to its `_category.md`, untouched
     *   a section written here      forgets the name, and is titled from its
     *                               slug again if any pages are still in it
     *
     * What it does not do is remove the pages, which live in another table and
     * are removed one at a time from the page screen. A section whose pages
     * are all removed disappears from the wiki by itself -- the index has
     * always dropped empty sections -- so there is no separate way to take a
     * section offline, and nothing here can orphan a page that the dashboard
     * would then be unable to reach.
     */
    public function destroy(string $slug): JsonResponse
    {
        $deleted = WikiCategory::query()->whereKey($slug)->delete();

        abort_if($deleted === 0, 404, 'That section has nothing to revert.');

        $this->invalidate();

        /*
         * What the section is now, read back after the flush rather than
         * worked out on the screen: whether it is still on the wiki at all
         * depends on the pages in it, and what it is called now depends on a
         * `_category.md` the dashboard has never seen.
         */
        $section = $this->wiki->section($slug);

        return response()->json([
            'message' => 'The section has been reverted.',
            'data' => $section === null ? null : [
                'title' => $section['title'],
                'pages' => $section['count'],
            ],
        ]);
    }

    /**
     * Forget everything cached about the wiki.
     *
     * Both halves matter: the overrides are memoised for the request, and the
     * directory scan may be cached for longer if the operator has set
     * wiki.cache_seconds.
     */
    private function invalidate(): void
    {
        $this->overrides->flush();
        $this->wiki->forgetIndex();
    }
}
