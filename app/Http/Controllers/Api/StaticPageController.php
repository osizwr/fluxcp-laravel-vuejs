<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\StaticPage;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operator-authored static pages.
 *
 * Ports modules/pages/index.php, content.php, add.php, edit.php and
 * delete.php.
 *
 * `content` is the only public action: it serves one page by its path. The
 * rest are the administrator's side and are held at Administrator by the
 * permission map, as in the legacy.
 */
final class StaticPageController
{
    /**
     * The page body as the operator wrote it.
     *
     * Served as-is, and the client renders it as text rather than as HTML.
     * The legacy templates echoed this column unescaped, which made the page
     * editor a stored-XSS vector for anybody who reached it -- and the editor
     * is reachable by every administrator, not only the person who set the
     * server up. See docs/MIGRATION_DECISIONS.md (D20).
     */
    public function show(string $path): JsonResponse
    {
        $page = StaticPage::query()->atPath($path)->first();

        abort_if($page === null, 404, 'No such page.');

        return response()->json([
            'data' => [
                'path' => $page->path,
                'title' => $page->title,
                'body' => $page->body,
                'updated_at' => $page->modified?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Every page, for the administrator's listing and for building navigation.
     *
     * @throws ValidationException
     */
    public function index(Request $request): JsonResponse
    {
        $list = new ListQuery(
            sortable: ['title' => 'title', 'path' => 'path', 'updated' => 'modified'],
            defaultSort: 'path',
            defaultDirection: 'asc',
        );

        // The body is deliberately not selected: a listing of twenty pages
        // would otherwise carry every word of all of them.
        $page = $list->paginate(
            StaticPage::query()->select('id', 'path', 'title', 'modified')->toBase(),
            $request,
        );

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'id' => (int) $row->id,
                'path' => (string) $row->path,
                'title' => (string) $row->title,
                'updated_at' => $row->modified === null
                    ? null
                    : Carbon::parse((string) $row->modified)->toIso8601String(),
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
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePage($request);

        $page = new StaticPage;

        $page->forceFill([
            'path' => StaticPage::normalisePath($validated['path']),
            'title' => $validated['title'],
            'body' => $validated['body'],
            'modified' => now(),
        ])->save();

        return response()->json([
            'message' => 'The page has been created.',
            'data' => ['id' => $page->id, 'path' => $page->path],
        ], 201);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, StaticPage $page): JsonResponse
    {
        $validated = $this->validatePage($request, $page);

        $page->forceFill([
            'path' => StaticPage::normalisePath($validated['path']),
            'title' => $validated['title'],
            'body' => $validated['body'],
            'modified' => now(),
        ])->save();

        return response()->json([
            'message' => 'The page has been saved.',
            'data' => ['id' => $page->id, 'path' => $page->path],
        ]);
    }

    public function destroy(StaticPage $page): JsonResponse
    {
        $page->delete();

        return response()->json(['message' => 'The page has been deleted.']);
    }

    /**
     * @return array{path: string, title: string, body: string}
     *
     * @throws ValidationException
     */
    private function validatePage(Request $request, ?StaticPage $existing = null): array
    {
        /** @var array{path: string, title: string, body: string} $validated */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            /*
             * Letters, digits, dashes and slashes only. The path becomes a URL
             * segment, so anything that could be read as a traversal or a
             * query string is refused rather than escaped later.
             *
             * Uniqueness is checked against the normalised form, because
             * `Rules` and `rules` are the same page.
             */
            'path' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-\/]*$/',
                Rule::unique(
                    (new StaticPage)->getConnectionName().'.cp_cmspages',
                    'path',
                )->ignore($existing?->id)->where(
                    fn ($query) => $query->where('path', StaticPage::normalisePath(
                        (string) $request->input('path', ''),
                    )),
                ),
            ],
            'body' => ['required', 'string', 'max:65535'],
        ], [
            'path.regex' => 'A page path may contain letters, numbers, dashes and slashes, and must start with a letter or number.',
            'path.unique' => 'Another page already uses that path.',
        ]);

        return $validated;
    }
}
