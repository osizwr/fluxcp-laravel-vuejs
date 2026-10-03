<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Str;

/**
 * Turns operator-authored content into HTML that is safe to display.
 *
 * ---------------------------------------------------------------------------
 * The problem this solves
 * ---------------------------------------------------------------------------
 *
 * FluxCP's news and static-page templates echoed `cp_cmsnews.body` and
 * `cp_cmspages.body` straight into the page with no escaping, so whatever an
 * administrator typed into the editor was executed in every visitor's browser.
 * That is a stored cross-site-scripting vector, and the editor is reachable by
 * every administrator rather than only by whoever set the server up -- so it
 * only takes one compromised or careless staff account to reach every player
 * who loads the front page.
 *
 * ---------------------------------------------------------------------------
 * What this does instead
 * ---------------------------------------------------------------------------
 *
 * The body is parsed as Markdown with raw HTML *stripped* and unsafe links
 * disallowed. Operators get headings, lists, emphasis, links and images
 * without being able to inject a script, an iframe, or an event handler.
 *
 * Both forms are served: `body` unchanged for the editor, and `body_html` for
 * display. Nothing is rewritten in the database, so an existing FluxCP
 * install's content is left exactly as it was found.
 *
 * ---------------------------------------------------------------------------
 * The cost, stated plainly
 * ---------------------------------------------------------------------------
 *
 * Existing content written as HTML renders with its tags removed rather than
 * as formatted markup. An operator migrating from FluxCP will want to rewrite
 * those entries as Markdown, and the raw body is preserved so nothing is lost
 * in the meantime. Allowing a "safe subset" of HTML instead would mean
 * shipping a sanitiser and maintaining an allow-list of tags and attributes,
 * which is a larger thing to get wrong quietly.
 *
 * See docs/MIGRATION_DECISIONS.md (D20).
 */
final readonly class ContentRenderer
{
    public function toHtml(?string $body): string
    {
        $body = trim((string) $body);

        if ($body === '') {
            return '';
        }

        return Str::markdown($body, [
            /*
             * The setting that matters. 'strip' removes raw HTML blocks and
             * inline tags entirely; the alternative, 'escape', would render
             * them visibly as text, which is noisier for an operator whose
             * content predates this.
             */
            'html_input' => 'strip',

            /*
             * Refuses javascript:, data: and vbscript: URLs in links and
             * images. Without it, Markdown's own link syntax is a script
             * vector even with HTML stripped.
             */
            'allow_unsafe_links' => false,

            // A runaway nesting depth is a denial-of-service vector on a
            // parser, and nothing legitimate here goes near it.
            'max_nesting_level' => 20,
        ]);
    }

    /**
     * A short plain-text summary, for listings and meta descriptions.
     */
    public function excerpt(?string $body, int $length = 200): string
    {
        $text = trim(strip_tags($this->toHtml($body)));

        // Collapse the whitespace Markdown leaves between blocks, so an
        // excerpt is one readable line rather than a column of fragments.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return Str::limit($text, $length);
    }
}
