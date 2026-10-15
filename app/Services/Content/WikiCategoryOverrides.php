<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\WikiCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * The wiki sections that came from the dashboard rather than from disk.
 *
 * The companion to WikiOverrides, and deliberately a second class rather than
 * a second method on it: one answers "what has somebody written about a page",
 * the other "what has somebody written about a section", and WikiLibrary
 * merges them at different points for different reasons -- see read().
 *
 * A missing table is treated as "nothing has been written", for the reason
 * WikiOverrides does the same: the wiki works perfectly well without this
 * feature, and an upgrade that has not been migrated yet should leave an
 * operator with the guide they had rather than with a 500 on /wiki.
 */
final class WikiCategoryOverrides
{
    /** @var array<string, WikiCategory>|null */
    private ?array $categories = null;

    /**
     * Every override, keyed by slug.
     *
     * @return array<string, WikiCategory>
     */
    public function all(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        try {
            return $this->categories = WikiCategory::query()
                ->get()
                ->keyBy('slug')
                ->all();
        } catch (QueryException $e) {
            Log::warning('Wiki section overrides could not be read; serving the sections on disk.', [
                'exception' => $e->getMessage(),
            ]);

            return $this->categories = [];
        }
    }

    public function find(string $slug): ?WikiCategory
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * Forget what was read this request.
     *
     * Called after a write so an editor that saves and then re-reads does not
     * get the version it just replaced.
     */
    public function flush(): void
    {
        $this->categories = null;
    }
}
