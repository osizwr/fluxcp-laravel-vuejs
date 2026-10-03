<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public news.
 *
 * Read-only. The admin side of FluxCP's news CMS -- manage, add, edit, delete
 * -- is not built, so there is deliberately no write path here rather than a
 * stub that looks like one.
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
}
