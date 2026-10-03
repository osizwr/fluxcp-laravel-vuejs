<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NewsArticle;
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
             * Rich text from the legacy editor. Included only when a single
             * article was requested, and the client must render it as HTML
             * deliberately rather than by accident -- see the news page.
             */
            'body' => $this->when($request->routeIs('news.view'), fn (): string => $this->body),
        ];
    }
}
