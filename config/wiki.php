<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The wiki
|--------------------------------------------------------------------------
|
| An operator-authored guide served at /wiki: how to get playing, what this
| server changed, and the answers that otherwise get typed into Discord every
| day.
|
| ---------------------------------------------------------------------------
| Why the content is on disk rather than in the database
| ---------------------------------------------------------------------------
|
| The panel already has a CMS for one-off pages -- cp_cmspages, reachable at
| /api/pages -- and the wiki deliberately does not use it. A wiki is not a
| handful of pages; it is a structured body of writing that gets revised for
| years, by several people, and the things that matter about it are the things
| a filesystem gives for free:
|
|   - it is in version control, so a wrong edit is a revert rather than an
|     apology, and "who changed the rates page" has an answer;
|   - it can be written in an editor, reviewed in a pull request, and deployed
|     with everything else, instead of being typed into a textarea;
|   - it survives a database restore from before the page was written.
|
| The trade is that adding a page needs filesystem access, which is a worse
| deal for an operator whose only access is a web browser. That operator is
| better served by cp_cmspages, which is still there.
|
| ---------------------------------------------------------------------------
| How the content is laid out
| ---------------------------------------------------------------------------
|
|   resources/wiki/
|     getting-started/
|       _category.md              <- the section's own title and description
|       welcome.md                <- a page, reachable at /wiki/getting-started/welcome
|       creating-an-account.md
|     help/
|       _category.md
|       faq.md
|
| One directory per section, one Markdown file per page, and a leading
| underscore on anything that is not a page. The directory and file names are
| the URL, so they are the one thing worth choosing carefully: renaming a file
| breaks whatever linked to it.
|
| Each file opens with a front-matter block, which is where the title and the
| ordering come from:
|
|   ---
|   title: Creating an account
|   summary: What you need, and what happens after you register.
|   order: 2
|   updated: 2026-10-07
|   ---
|
|   The body, in Markdown.
|
| Everything in the block is optional. A file without a title is titled from
| its filename, and a file without an order is sorted after the ones that have
| one. See docs/WIKI.md.
|
| ---------------------------------------------------------------------------
| The content is rendered as Markdown with HTML stripped
| ---------------------------------------------------------------------------
|
| The same rule the news and the CMS pages follow, through the same renderer:
| headings, lists, links, tables and images, and no raw HTML. That is a
| narrower allowance than a filesystem-only author strictly needs -- someone
| who can write to resources/wiki/ can usually write to the templates too --
| but the rule is worth keeping uniform, because content moves: a page pasted
| out of the wiki and into the CMS should not change what it is allowed to do.
| See docs/MIGRATION_DECISIONS.md (D20).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Whether the wiki is served at all
    |--------------------------------------------------------------------------
    |
    | On by default, because a panel that ships starter pages and then refuses
    | to serve them is confusing. An operator who keeps their guide elsewhere
    | -- a GitBook, a forum, a Notion -- turns this off and points
    | GAME_WIKI_URL at it instead; the navigation follows the URL when one is
    | set, so the two never both appear.
    |
    */

    'enabled' => (bool) env('WIKI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Where the pages live
    |--------------------------------------------------------------------------
    |
    | Relative paths resolve from the project root. A theme does not get to
    | override this: the wiki is the server's words, not the skin's, and a
    | change of skin must not change what the guide says.
    |
    */

    'path' => env('WIKI_PATH', 'resources/wiki'),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Off by default, which is the unusual choice and the deliberate one: with
    | it off, an operator who fixes a typo sees the fix on the next reload,
    | and the cost is reading a few dozen small files per request.
    |
    | Turn it on when the wiki has grown past a hundred pages or the files sit
    | on something slower than a local disk. Edits then take up to this many
    | seconds to appear, and `php artisan cache:clear` shortens that to none.
    |
    */

    'cache_seconds' => (int) env('WIKI_CACHE_SECONDS', 0),

    /*
    |--------------------------------------------------------------------------
    | Images written into a page from the dashboard
    |--------------------------------------------------------------------------
    |
    | The pages themselves are Markdown -- on disk or in `wiki_pages` -- and an
    | image is a URL inside one. These are the rules for the only images this
    | panel puts there itself: the ones an operator drops into the editor.
    |
    | Stored on the public disk, which means `php artisan storage:link` has to
    | have been run or the files are written and then served as 404s. Named
    | from a hash of their own contents, so the same screenshot pasted into
    | four pages is one file, and so nothing an operator names can decide
    | where a file lands.
    |
    | SVG is deliberately not in the list. It is an image in the way that
    | matters to a browser and a document in the way that matters to a
    | sanitiser -- it can carry script -- and the whole content pipeline is
    | built on not serving operator-authored markup. See ContentRenderer.
    |
    */

    'uploads' => [
        'disk' => env('WIKI_IMAGE_DISK', 'public'),
        'directory' => env('WIKI_IMAGE_DIRECTORY', 'wiki'),

        // Kilobytes. A screenshot of a game client is a few hundred.
        'max_kilobytes' => (int) env('WIKI_IMAGE_MAX_KB', 4096),

        /*
         * Checked against the extension and the detected type, both. The
         * pairing is what keeps `payload.php.png` from being stored as PHP.
         */
        'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],

        /*
         * A cap on the decoded size as well as the file size. A 10KB PNG can
         * declare itself 50,000 pixels square and cost gigabytes to open,
         * which is a denial of service the byte count does not catch.
         */
        'max_width' => (int) env('WIKI_IMAGE_MAX_WIDTH', 6000),
        'max_height' => (int) env('WIKI_IMAGE_MAX_HEIGHT', 6000),

        /*
        |----------------------------------------------------------------------
        | A section's own art
        |----------------------------------------------------------------------
        |
        | The picture on a section's tile on /wiki. A different thing from an
        | image inside a page, with different rules, because it is a different
        | job: the tiles are a grid of equal squares and the index draws all of
        | them at once.
        |
        | One exact size, and not a maximum. A tile that has to be cropped or
        | letterboxed to fit is a decision this panel would be making about
        | somebody's artwork without being able to see it, and the two ways out
        | of that -- squashing it, or cropping the middle out -- are both worse
        | than asking for the square that is going to be drawn.
        |
        | Anything the server can decode goes in; what comes out is always
        | WebP. That is the panel making a call it is entitled to make: these
        | are its own tiles, nine of them on a page that is frequently
        | somebody's first, and WebP is a third of the PNG at a size nobody can
        | tell apart.
        |
        */

        'section_image' => [
            'directory' => env('WIKI_SECTION_IMAGE_DIRECTORY', 'wiki/sections'),

            /*
             * Not settable from the environment, on purpose. The dashboard
             * checks the same square before it uploads, so that somebody who
             * exported at the wrong size is told in the browser instead of
             * after sending eight megabytes -- and a number that could differ
             * between the two halves would make that check wrong rather than
             * merely absent. AdminWikiSectionEditPage holds the pair.
             */
            'width' => 1080,
            'height' => 1080,

            // Kilobytes, before conversion. What comes out is far smaller.
            'max_kilobytes' => (int) env('WIKI_SECTION_IMAGE_MAX_KB', 8192),

            /*
             * Everything GD here is likely to have been built with, minus
             * SVG -- which is not on the list for the reason it is not on the
             * other one: it can carry script.
             */
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],

            // 82 is the usual sweet spot: visually lossless on artwork, and
            // roughly half the bytes of 95.
            'quality' => (int) env('WIKI_SECTION_IMAGE_QUALITY', 82),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Plain substring matching over the titles, summaries and bodies, ranked
    | title-first. Not an index and not a ranking engine: a server's guide is
    | tens of pages, and the honest implementation of searching tens of pages
    | is to read them.
    |
    */

    'search' => [
        // Below this, every page matches and the result is noise.
        'minimum_length' => 2,
        'limit' => 12,
        // How much of the surrounding sentence to show under a hit.
        'snippet_length' => 160,
    ],

    /*
    |--------------------------------------------------------------------------
    | The shortcuts above the search box
    |--------------------------------------------------------------------------
    |
    | The handful of pages that answer most of the questions, offered as chips
    | so nobody has to search for them. Paths, exactly as they appear in a URL
    | after /wiki/. One that does not resolve to a page is dropped rather than
    | rendered as a dead chip, so pruning a page cannot leave a broken link
    | here.
    |
    */

    'popular' => [
        'getting-started/overview',
        'game-access/overview',
        'server-guide/overview',
        'events/overview',
    ],

    /*
    |--------------------------------------------------------------------------
    | How many pages the "recently updated" list holds
    |--------------------------------------------------------------------------
    */

    'recent_limit' => 6,

    /*
    |--------------------------------------------------------------------------
    | Server rates, beside the search box
    |--------------------------------------------------------------------------
    |
    | The numbers a visitor came to the wiki to check. Empty by default and
    | absent from the page until an operator fills it in, because the panel
    | cannot read rates out of rAthena's configuration and a default of "5x"
    | would be this panel making a claim about somebody else's server.
    |
    | Each entry is a label, the figure itself, and an optional word of
    | context. The figure is free text rather than a number so that "15x",
    | "0.05%" and "Disabled" are all sayable.
    |
    |   ['label' => 'Base EXP',  'value' => '15x'],
    |   ['label' => 'Job EXP',   'value' => '15x'],
    |   ['label' => 'Drops',     'value' => '3x',  'note' => 'Normal items'],
    |   ['label' => 'MvP cards', 'value' => '1x'],
    |
    */

    'rates' => [],

];
