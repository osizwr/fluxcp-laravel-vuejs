<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
use App\Models\NewsArticleTag;
use App\Services\Content\NewsFeed;
use App\Support\Http\ListQuery;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * News: the public listing and the operator's editor.
 *
 * Ports modules/news/index.php, view.php, manage.php, add.php, edit.php and
 * delete.php.
 *
 * Reading is public; writing is held at Administrator by the permission map,
 * as in the legacy access file.
 *
 * Bodies are stored exactly as the operator typed them and rendered through
 * ContentRenderer for display -- Markdown with raw HTML stripped. The legacy
 * templates echoed the column into the page unescaped, which made this editor
 * a stored-XSS vector reachable by every administrator. See
 * docs/MIGRATION_DECISIONS.md (D20).
 */
final class NewsController
{
    public function __construct(
        private readonly NewsFeed $feed,
        private readonly ServerRegistry $servers,
    ) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $perPage = min(
            $request->integer('per_page', (int) config('panel.pagination.per_page', 20)),
            (int) config('panel.pagination.max_per_page', 100),
        );

        /*
         * An operator may point the panel at their forum's feed instead of
         * writing news here (FluxCP's CMSNewsType). The items come back in the
         * same shape, so a client renders either without knowing which.
         *
         * Not paginated: a feed returns what it returns, and asking a third
         * party for page four of their announcements is not a thing feeds do.
         */
        if ($this->feed->isConfigured()) {
            $items = $this->feed->items($perPage);

            return response()->json([
                'data' => $items,
                'meta' => ['source' => 'feed', 'total' => count($items)],
            ]);
        }

        return NewsArticleResource::collection(
            NewsArticle::query()
                ->with('tagRow')
                ->newestFirst()
                ->paginate($perPage)
                ->withQueryString(),
        )->additional(['meta' => ['source' => 'panel']]);
    }

    public function show(int $article): NewsArticleResource
    {
        return NewsArticleResource::make(
            NewsArticle::query()->with('tagRow')->findOrFail($article),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The operator's side
    |--------------------------------------------------------------------------
    */

    /**
     * Every article, for the management screen.
     *
     * Separate from `index` because it lists drafts-in-spirit and ordering by
     * id rather than date, and because the public listing is paginated for
     * readers while this one is paginated for editing.
     *
     * @throws ValidationException
     */
    public function manage(Request $request): JsonResponse
    {
        $list = new ListQuery(
            sortable: ['title' => 'title', 'author' => 'author', 'published' => 'created'],
            defaultSort: 'published',
        );

        $page = $list->paginate(
            NewsArticle::query()->select('id', 'title', 'author', 'created', 'modified')->toBase(),
            $request,
        );

        /*
         * One query for the page's tags rather than one per row: the listing
         * is a query builder result, not models, so there is no relation to
         * eager-load.
         */
        $tags = $this->tagsFor(array_map(static fn (object $row): int => (int) $row->id, $page->items()));

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'author' => (string) $row->author,
                'tag' => $tags[(int) $row->id] ?? null,
                'published_at' => $this->iso($row->created),
                'updated_at' => $this->iso($row->modified),
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * One article as its author typed it, for the editor.
     */
    public function edit(int $article): NewsArticleResource
    {
        return NewsArticleResource::make(NewsArticle::query()->with('tagRow')->findOrFail($article));
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateArticle($request);

        $article = new NewsArticle;

        $tag = $validated['tag'];
        unset($validated['tag']);

        $article->forceFill([
            ...$validated,
            /*
             * The author defaults to the account writing it rather than being
             * free text, so the byline cannot be set to somebody else by
             * whoever has the editor open.
             */
            'author' => $validated['author'] !== ''
                ? $validated['author']
                : (string) $request->user()?->userid,
            'created' => now(),
            'modified' => now(),
        ])->save();

        $this->writeTag($article->id, $tag);

        return response()->json([
            'message' => 'The article has been published.',
            'data' => ['id' => $article->id],
        ], 201);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, NewsArticle $article): JsonResponse
    {
        $validated = $this->validateArticle($request);

        $tag = $validated['tag'];
        unset($validated['tag']);

        $article->forceFill([
            ...$validated,
            'author' => $validated['author'] !== '' ? $validated['author'] : $article->author,
            // `created` is left alone: editing an article does not republish
            // it, and moving the date would reorder the front page.
            'modified' => now(),
        ])->save();

        $this->writeTag($article->id, $tag);

        return response()->json(['message' => 'The article has been saved.']);
    }

    public function destroy(NewsArticle $article): JsonResponse
    {
        /*
         * The tag goes with the article. It is in another database, so no
         * foreign key can do this -- and leaving it would attach the word to
         * whichever article is next given this id, which happens after a
         * restart resets the auto-increment counter.
         */
        $this->writeTag($article->id, null);

        $article->delete();

        return response()->json(['message' => 'The article has been deleted.']);
    }

    /**
     * @return array{title: string, body: string, link: string, author: string, tag: string|null}
     *
     * @throws ValidationException
     */
    private function validateArticle(Request $request): array
    {
        $validated = $request->validate([
            // The legacy columns are varchar(100); longer is truncated on
            // write, so it is refused here instead.
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:65535'],
            /*
             * An optional "read more" target. Restricted to http and https so
             * the field cannot carry a javascript: URL into the listing, which
             * renders it as a link.
             */
            'link' => ['nullable', 'string', 'max:100', 'url:http,https'],
            'author' => ['nullable', 'string', 'max:100'],
            /*
             * The word beside the headline. Free text, because the panel
             * cannot know what a server calls its posts, but short: it is
             * drawn as a badge, and a sentence in a badge is a broken layout
             * rather than a tag.
             */
            'tag' => ['nullable', 'string', 'max:32'],
        ]);

        $tag = trim((string) ($validated['tag'] ?? ''));

        return [
            'title' => $validated['title'],
            'body' => $validated['body'],
            'link' => (string) ($validated['link'] ?? ''),
            'author' => (string) ($validated['author'] ?? ''),
            'tag' => $tag === '' ? null : $tag,
        ];
    }

    /**
     * Set, replace or remove an article's tag.
     *
     * Null is "no tag", which is stored as no row rather than as an empty
     * string: the table is an overlay on articles that have one, and an
     * article nobody tagged should leave no trace in it.
     */
    private function writeTag(int $article, ?string $tag): void
    {
        $key = ['server_group' => $this->servers->current()->key, 'article_id' => $article];

        if ($tag === null) {
            NewsArticleTag::query()->where($key)->delete();

            return;
        }

        NewsArticleTag::query()->updateOrCreate($key, ['tag' => $tag]);
    }

    /**
     * The tags for a page of articles, keyed by article id.
     *
     * @param  list<int>  $articles
     * @return array<int, string>
     */
    private function tagsFor(array $articles): array
    {
        if ($articles === []) {
            return [];
        }

        return NewsArticleTag::query()
            ->where('server_group', $this->servers->current()->key)
            ->whereIn('article_id', $articles)
            ->pluck('tag', 'article_id')
            ->all();
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
