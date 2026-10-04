<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * News taken from an external feed instead of the panel's own table.
 *
 * Ports FluxCP's `CMSNewsType = 2`, which many servers use so their forum's
 * announcements appear on the panel without being written twice. The legacy
 * read the feed with `file_get_contents(Flux::config('CMSNewsRSS'))` on every
 * request: no timeout, no size limit, and no cache, so a forum that stopped
 * answering took the front page down with it.
 *
 * ---------------------------------------------------------------------------
 * What is done differently, and why
 * ---------------------------------------------------------------------------
 *
 * The feed is somebody else's content arriving over the network, which makes
 * it the least trustworthy input the panel has:
 *
 *   - a timeout and a size cap, so a slow or enormous response cannot hold a
 *     request open or exhaust memory.
 *   - a cache, with the last good result served while the feed is unreachable.
 *     A news block is not worth a failed page.
 *   - LIBXML_NONET when parsing, so the parser cannot be made to fetch
 *     anything of its own accord.
 *   - every field stripped to plain text. A feed's description is HTML by
 *     specification, and rendering it would hand whoever runs that forum the
 *     ability to put markup -- or a script -- on this panel's front page. The
 *     text is kept and the markup is not, which is the same rule D20 applies
 *     to the panel's own content.
 *
 * The shape returned matches NewsArticleResource, so a client renders news the
 * same way whichever source an operator chose.
 */
final readonly class NewsFeed
{
    /** How much of a response to read before giving up on it. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private CacheRepository $cache) {}

    /**
     * Whether the operator has pointed the panel at a feed.
     */
    public function isConfigured(): bool
    {
        return config('panel.news.source') === 'feed' && $this->url() !== null;
    }

    /**
     * The latest items, newest first.
     *
     * Never throws: a feed that cannot be read yields the last good result, or
     * an empty list if there has never been one. The caller's page is worth
     * more than the news block on it.
     *
     * @return list<array<string, mixed>>
     */
    public function items(?int $limit = null): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $limit = max(1, $limit ?? (int) config('panel.news.feed_limit', 4));
        $seconds = max(0, (int) config('panel.news.feed_cache_seconds', 900));

        $key = 'news:feed:'.sha1((string) $this->url());

        $items = $seconds > 0
            ? $this->cache->remember($key, $seconds, fn (): array => $this->fetch($key))
            : $this->fetch($key);

        return array_slice($items, 0, $limit);
    }

    /*
    |--------------------------------------------------------------------------
    | Fetching
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $key): array
    {
        try {
            $response = Http::withHeaders(['Accept' => 'application/rss+xml, application/xml, text/xml'])
                ->timeout((int) config('panel.news.feed_timeout_seconds', 5))
                ->connectTimeout((int) config('panel.news.feed_timeout_seconds', 5))
                // The panel follows the feed, not a chain of redirects to
                // somewhere else.
                ->withoutRedirecting()
                ->get((string) $this->url());

            if (! $response->successful()) {
                return $this->lastGood($key);
            }

            $body = substr($response->body(), 0, self::MAX_BYTES);
            $items = $this->parse($body);

            if ($items === []) {
                return $this->lastGood($key);
            }

            /*
             * Kept separately from the cache entry so an unreachable feed can
             * still be answered after that entry expires. A stale headline is
             * better than an empty block.
             */
            $this->cache->forever($key.':last', $items);

            return $items;
        } catch (Throwable) {
            return $this->lastGood($key);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lastGood(string $key): array
    {
        $items = $this->cache->get($key.':last', []);

        return is_array($items) ? $items : [];
    }

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    */

    /**
     * Read RSS 2.0 or Atom, whichever arrived.
     *
     * Both are in use by the forum software a Ragnarok server is likely to
     * run, and telling them apart is cheaper than making the operator declare
     * which one they have.
     *
     * @return list<array<string, mixed>>
     */
    private function parse(string $body): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string(
                $body,
                SimpleXMLElement::class,
                // NONET forbids the parser any network access of its own;
                // NOCDATA folds CDATA sections into ordinary strings so a
                // description wrapped in one is not an empty element.
                LIBXML_NONET | LIBXML_NOCDATA,
            );
        } catch (Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            return [];
        }

        $entries = isset($xml->channel->item)
            ? $xml->channel->item          // RSS 2.0
            : ($xml->entry ?? null);       // Atom

        if ($entries === null) {
            return [];
        }

        $items = [];
        $index = 0;

        foreach ($entries as $entry) {
            $items[] = $this->shape($entry, $index++);
        }

        return $items;
    }

    /**
     * One entry, in the shape NewsArticleResource produces.
     *
     * The id is the entry's position rather than anything from the feed: these
     * are not panel articles, there is no detail route to reach them by, and a
     * client keying a list needs something stable within one response.
     *
     * @return array<string, mixed>
     */
    private function shape(SimpleXMLElement $entry, int $index): array
    {
        $title = $this->text($entry->title ?? null);
        $description = $this->text($entry->description ?? $entry->summary ?? $entry->content ?? null);
        $published = $this->text($entry->pubDate ?? $entry->published ?? $entry->updated ?? null);

        return [
            'id' => $index,
            'title' => $title === '' ? 'Untitled' : $title,
            'excerpt' => Str::limit($description, 180),
            'author' => $this->author($entry),
            'link' => $this->link($entry),
            'published_at' => $this->timestamp($published),
            'updated_at' => null,
            // So a client can tell a feed item from a panel article: there is
            // no page on this panel to open it on.
            'external' => true,
        ];
    }

    private function author(SimpleXMLElement $entry): string
    {
        $author = $entry->author ?? null;

        // Atom nests the name; RSS puts an address in the element itself.
        if ($author !== null && isset($author->name)) {
            return $this->text($author->name);
        }

        $creator = $entry->children('http://purl.org/dc/elements/1.1/')->creator ?? null;

        return $this->text($author) ?: $this->text($creator);
    }

    /**
     * The entry's link, and only if it is one the browser should follow.
     */
    private function link(SimpleXMLElement $entry): ?string
    {
        $candidate = $this->text($entry->link ?? null);

        // Atom carries it as an attribute rather than as text.
        if ($candidate === '' && isset($entry->link['href'])) {
            $candidate = $this->text($entry->link['href']);
        }

        if ($candidate === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $candidate : null;
    }

    /**
     * An element's text, as text.
     *
     * Tags are removed rather than escaped. A feed description is HTML by
     * specification and this is somebody else's HTML, so none of it is kept.
     *
     * Script and style elements are removed with their contents first, which
     * strip_tags() alone does not do -- it takes the tags off and leaves what
     * was between them, so `<script>alert(1)</script>` becomes the visible
     * text `alert(1)`. Harmless as text and still not something to put on a
     * front page as though the forum had written it.
     */
    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = (string) preg_replace(
            '#<(script|style|template)\b[^>]*>.*?</\1\s*>#is',
            '',
            $text,
        );

        // An unclosed script element would survive the pattern above, so
        // anything from such a tag to the end is dropped as well.
        $text = (string) preg_replace('#<(script|style|template)\b[^>]*>.*$#is', '', $text);

        $text = strip_tags($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A feed date in ISO 8601, or null when it is unreadable.
     *
     * Feeds carry RFC 822, RFC 3339 and a certain amount of improvisation, so
     * a date that cannot be parsed is dropped rather than guessed at.
     */
    private function timestamp(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $stamp = strtotime($value);

        return $stamp === false ? null : date(DATE_ATOM, $stamp);
    }

    private function url(): ?string
    {
        $url = trim((string) config('panel.news.feed_url', ''));

        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        // An operator sets this, but a typo that turns it into a file:// or
        // php:// URL should not become a file read.
        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
