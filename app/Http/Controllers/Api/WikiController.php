<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Content\WikiLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The server's own guide, at /wiki.
 *
 * Not a port of anything: FluxCP had no wiki, and the servers running it kept
 * their guide on a forum thread, a GitBook or a pinned Discord message. This
 * is that guide, in the panel, so it carries the server's branding and is
 * searchable alongside everything else.
 *
 * All three actions are public. A guide that needs an account to read is a
 * guide that cannot answer "how do I make an account", which is the question
 * it exists for.
 *
 * The content is read from disk by WikiLibrary; this only shapes it.
 */
final class WikiController
{
    public function __construct(private readonly WikiLibrary $wiki) {}

    /**
     * The sections, their pages, and what changed recently.
     *
     * One request serves the landing page and every article's sidebar, so a
     * client fetches it once and keeps it.
     */
    public function index(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(['data' => $this->wiki->index()]);
    }

    /**
     * One page, rendered.
     */
    public function show(string $path): JsonResponse
    {
        $this->ensureEnabled();

        $article = $this->wiki->article(trim($path, '/'));

        abort_if($article === null, 404, 'No such wiki page.');

        return response()->json(['data' => $article]);
    }

    /**
     * Pages matching a term.
     *
     * A term that is too short is not an error -- it is somebody who has
     * typed one letter so far -- so it comes back as no results rather than
     * as a validation failure the search box would have to render.
     *
     * @throws ValidationException
     */
    public function search(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        /** @var array{q?: string} $validated */
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim($validated['q'] ?? '');
        $results = $this->wiki->search($term);

        return response()->json([
            'data' => $results,
            'meta' => [
                'term' => $term,
                'count' => count($results),
            ],
        ]);
    }

    /**
     * An operator who keeps their guide elsewhere has turned this off, and
     * the endpoints should then behave as though the feature was never built
     * rather than return empty collections that a client renders as a wiki
     * with nothing in it.
     */
    private function ensureEnabled(): void
    {
        abort_unless($this->wiki->isEnabled(), 404, 'This server does not publish a wiki.');
    }
}
