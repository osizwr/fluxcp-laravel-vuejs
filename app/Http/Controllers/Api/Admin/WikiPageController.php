<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\SaveWikiPageRequest;
use App\Models\Account;
use App\Models\WikiPage;
use App\Services\Content\WikiLibrary;
use App\Services\Content\WikiOverrides;
use App\Support\Content\FrontMatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The guide, edited from the dashboard.
 *
 * Every page here is one of three things, and the screen says which:
 *
 *   file       Markdown in version control, untouched by this dashboard
 *   database   a page written here that has no file behind it
 *   both       a file with an edit laid over it, which can be reverted
 *
 * Nothing in this controller writes to the filesystem. That is the whole
 * design: config/wiki.php keeps the guide in git so a wrong edit is a revert
 * rather than an apology, and an editor that overwrote those files would take
 * that away from every operator who does have filesystem access, in order to
 * serve the ones who do not. An overlay serves both -- at the cost, stated on
 * the screen, that an edited page stops tracking its file.
 */
final class WikiPageController
{
    public function __construct(
        private readonly WikiLibrary $wiki,
        private readonly WikiOverrides $overrides,
    ) {}

    /**
     * Every page the wiki has, with where each one comes from.
     *
     * Built from the library's own index rather than from the table, so the
     * listing is the wiki as a reader sees it -- files included -- rather than
     * only the rows somebody has already edited.
     */
    public function index(): JsonResponse
    {
        $sections = [];

        foreach ($this->wiki->index()['categories'] as $category) {
            $pages = [];

            foreach ($category['pages'] as $page) {
                $pages[] = [
                    'path' => $page['path'],
                    'title' => $page['title'],
                    'summary' => $page['summary'],
                    'updated_at' => $page['updated_at'],
                    'source' => $this->sourceOf($page['path']),
                ];
            }

            $sections[] = [
                'slug' => $category['slug'],
                'title' => $category['title'],
                'pages' => $pages,
            ];
        }

        /*
         * Hidden pages are not in the index -- that is what hiding does -- so
         * they are listed separately. Without this a page removed here would
         * simply vanish from the dashboard too, and there would be no way to
         * bring it back short of the database.
         */
        $hidden = [];

        foreach ($this->overrides->all() as $override) {
            if ($override->hidden) {
                $hidden[] = [
                    'path' => $override->path,
                    'title' => $override->title,
                    'category' => $override->category,
                ];
            }
        }

        return response()->json([
            'data' => ['sections' => $sections, 'hidden' => $hidden],
        ]);
    }

    /**
     * One page as Markdown, for the editor.
     *
     * An edited page is served from the row. A page that has only a file is
     * served from the file, so opening it in the editor starts from what is
     * actually published rather than from an empty box.
     */
    public function show(string $path): JsonResponse
    {
        $override = $this->overrides->find($path);

        if ($override !== null) {
            return response()->json(['data' => [
                'path' => $override->path,
                'category' => $override->category,
                'slug' => $override->slug,
                'title' => $override->title,
                'summary' => $override->summary,
                'sort_order' => $override->sort_order,
                'body' => (string) $override->body,
                'hidden' => $override->hidden,
                'source' => $this->sourceOf($path),
            ]]);
        }

        $article = $this->wiki->article($path);

        abort_if($article === null, 404, 'No such wiki page.');

        [$category, $slug] = explode('/', $path, 2);

        return response()->json(['data' => [
            'path' => $path,
            'category' => $category,
            'slug' => $slug,
            'title' => $article['title'],
            'summary' => $article['summary'],
            'sort_order' => null,
            'body' => $this->markdownOnDisk($path),
            'hidden' => false,
            'source' => 'file',
        ]]);
    }

    /**
     * Create a page, or save an edit to one.
     *
     * One action for both, because the difference is not one an operator is
     * aware of: editing a page that has only ever been a file creates the
     * first row for it, and there is no sense in which that is a different
     * thing from editing it again tomorrow.
     */
    public function store(SaveWikiPageRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $path = $data['category'].'/'.$data['slug'];

        $account = $request->user();

        $page = WikiPage::query()->updateOrCreate(['path' => $path], [
            'category' => $data['category'],
            'slug' => $data['slug'],
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
            'body' => $data['body'],
            /*
             * Saving a page brings it back. Somebody who removed a page and
             * then wrote it again means it to be there, and leaving it hidden
             * would be a save that appears to do nothing.
             */
            'hidden' => false,
            'updated_by' => $account instanceof Account ? $account->account_id : null,
        ]);

        $this->invalidate();

        return response()->json([
            'message' => 'The page has been saved.',
            'data' => ['path' => $page->path],
        ], $page->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Remove a page from the wiki.
     *
     * A tombstone rather than a deleted row, because for a page with a file
     * behind it the row is the only thing standing between the reader and
     * that file -- deleting it would publish the page again. Reverting is
     * what undoes this.
     */
    public function destroy(Request $request, string $path): JsonResponse
    {
        $existing = $this->overrides->find($path);
        $article = $existing === null ? $this->wiki->article($path) : null;

        abort_if($existing === null && $article === null, 404, 'No such wiki page.');

        [$category, $slug] = explode('/', $path, 2);

        $account = $request->user();

        /*
         * The title is carried onto the tombstone so the dashboard can list
         * what was removed by name. Falling back to the slug rather than to
         * nothing: the column is not nullable, and "getting-started" in the
         * removed list is still more use than a blank row.
         */
        $title = $existing?->title ?? $article['title'] ?? $slug;

        WikiPage::query()->updateOrCreate(['path' => $path], [
            'category' => $category,
            'slug' => $slug,
            'title' => is_string($title) && $title !== '' ? $title : $slug,
            'summary' => $existing?->summary,
            'sort_order' => $existing?->sort_order,
            'body' => $existing?->body,
            'hidden' => true,
            'updated_by' => $account instanceof Account ? $account->account_id : null,
        ]);

        $this->invalidate();

        return response()->json(['message' => 'The page has been removed.']);
    }

    /**
     * Drop the override, so the file on disk takes over again.
     *
     * For a page that never had a file this deletes it outright, which is the
     * same sentence from the operator's side: stop publishing what I wrote.
     */
    public function revert(string $path): JsonResponse
    {
        $deleted = WikiPage::query()->whereKey($path)->delete();

        abort_if($deleted === 0, 404, 'That page has nothing to revert.');

        $this->invalidate();

        return response()->json(['message' => 'The page has been reverted.']);
    }

    /**
     * Where a path's content comes from, as the dashboard labels it.
     */
    private function sourceOf(string $path): string
    {
        $override = $this->overrides->find($path);
        $onDisk = $this->markdownOnDisk($path) !== null;

        return match (true) {
            $override !== null && $onDisk => 'both',
            $override !== null => 'database',
            default => 'file',
        };
    }

    /**
     * The Markdown in the file behind a path, or null when there is none.
     *
     * Goes through the library's own scan rather than building a filename
     * from the path. That is the rule the whole wiki is read by and the
     * reason `..%2f..%2f.env` is a 404 here rather than a disclosure: a path
     * is matched against the set of pages that exist, and no filename is ever
     * derived from input.
     */
    private function markdownOnDisk(string $path): ?string
    {
        foreach ($this->wiki->files() as $page => $file) {
            if ($page !== $path) {
                continue;
            }

            $contents = @file_get_contents($file);

            return $contents === false ? null : FrontMatter::parse($contents)->body;
        }

        return null;
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
