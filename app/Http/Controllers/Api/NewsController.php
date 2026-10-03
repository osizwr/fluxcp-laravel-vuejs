<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
use App\Support\Http\ListQuery;
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
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            $request->integer('per_page', (int) config('panel.pagination.per_page', 20)),
            (int) config('panel.pagination.max_per_page', 100),
        );

        return NewsArticleResource::collection(
            NewsArticle::query()->newestFirst()->paginate($perPage)->withQueryString(),
        );
    }

    public function show(int $article): NewsArticleResource
    {
        return NewsArticleResource::make(
            NewsArticle::query()->findOrFail($article),
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

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'author' => (string) $row->author,
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
        return NewsArticleResource::make(NewsArticle::query()->findOrFail($article));
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateArticle($request);

        $article = new NewsArticle;

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

        $article->forceFill([
            ...$validated,
            'author' => $validated['author'] !== '' ? $validated['author'] : $article->author,
            // `created` is left alone: editing an article does not republish
            // it, and moving the date would reorder the front page.
            'modified' => now(),
        ])->save();

        return response()->json(['message' => 'The article has been saved.']);
    }

    public function destroy(NewsArticle $article): JsonResponse
    {
        $article->delete();

        return response()->json(['message' => 'The article has been deleted.']);
    }

    /**
     * @return array{title: string, body: string, link: string, author: string}
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
        ]);

        return [
            'title' => $validated['title'],
            'body' => $validated['body'],
            'link' => (string) ($validated['link'] ?? ''),
            'author' => (string) ($validated['author'] ?? ''),
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
