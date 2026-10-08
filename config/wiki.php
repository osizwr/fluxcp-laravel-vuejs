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
        'getting-started/welcome',
        'getting-started/creating-an-account',
        'getting-started/downloading-the-client',
        'help/frequently-asked-questions',
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
