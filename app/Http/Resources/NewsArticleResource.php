<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NewsArticle;
use App\Services\Content\ContentRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A news entry as the API exposes it.
 *
 * The full HTML body is sent only for a single article, never in a listing:
 * a list of twenty articles would otherwise ship twenty rich-text documents to
 * render three lines of each.
 *
 * @mixin NewsArticle
 */
final class NewsArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'excerpt' => $this->excerpt(),
            'author' => $this->author,
            'link' => $this->link !== '' ? $this->link : null,
            'published_at' => $this->created?->toIso8601String(),
            'updated_at' => $this->modified?->toIso8601String(),

            /*
             * Two forms, on the single-article view only.
             *
             * `body` is what the operator typed, unchanged, for the editor.
             * `body_html` is that run through ContentRenderer: Markdown with
             * raw HTML stripped and unsafe links refused, which is the form
             * the client displays.
             *
             * The legacy templates echoed this column straight into the page,
             * making the news editor a stored-XSS vector reachable by every
             * administrator. See docs/MIGRATION_DECISIONS.md (D20).
             */
            'body' => $this->when(
                $request->routeIs('news.view') || $request->routeIs('news.edit'),
                fn (): string => $this->body,
            ),

            'body_html' => $this->when(
                $request->routeIs('news.view'),
                fn (): string => app(ContentRenderer::class)->toHtml($this->body),
            ),
        ];
    }
}
