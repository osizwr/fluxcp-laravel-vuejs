<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\WikiPage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * The wiki pages that came from the dashboard rather than from disk.
 *
 * Read once per request and handed to WikiLibrary, which merges them over the
 * directory scan. Nothing here knows what a wiki looks like; it only answers
 * "what has somebody written in the browser".
 *
 * A missing table is treated as "nothing has been written", for the same
 * reason the settings store does it: the wiki works perfectly well without
 * this feature, and an upgrade that has not been migrated yet should leave an
 * operator with the guide they had rather than with a 500 on /wiki.
 */
final class WikiOverrides
{
    /** @var array<string, WikiPage>|null */
    private ?array $pages = null;

    /**
     * Every override, keyed by `category/slug`.
     *
     * @return array<string, WikiPage>
     */
    public function all(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        try {
            return $this->pages = WikiPage::query()
                ->get()
                ->keyBy('path')
                ->all();
        } catch (QueryException $e) {
            Log::warning('Wiki overrides could not be read; serving the files on disk.', [
                'exception' => $e->getMessage(),
            ]);

            return $this->pages = [];
        }
    }

    public function find(string $path): ?WikiPage
    {
        return $this->all()[$path] ?? null;
    }

    /**
     * Forget what was read this request.
     *
     * Called after a write so an editor that saves and then re-reads does not
     * get the version it just replaced.
     */
    public function flush(): void
    {
        $this->pages = null;
    }
}
