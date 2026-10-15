<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\WikiPage;
use Illuminate\Database\Seeder;

/**
 * The starter page behind each wiki section.
 *
 * Run with:  php artisan db:seed --class=WikiContentSeeder
 *
 * ---------------------------------------------------------------------------
 * Why the sections are on disk and the pages are in the database
 * ---------------------------------------------------------------------------
 *
 * Not an inconsistency, and worth the paragraph. A section's title, blurb,
 * icon and position come from `_category.md`, and nowhere else -- `wiki_pages`
 * has no column for any of them, and a section conjured out of a row alone is
 * titled from its slug, drawn without an icon and sorted after every section
 * that has a position. So the nine `_category.md` files in resources/wiki are
 * what makes the sections read in the order they were asked for rather than
 * alphabetically.
 *
 * The pages are rows because a row is what the dashboard can edit. An operator
 * whose only access is a browser can rewrite every one of these at
 * /admin/wiki without touching the filesystem, which is the whole point of the
 * override table. See the wiki_pages migration.
 *
 * The honest cost, since config/wiki.php spends some length arguing the other
 * way: a page edited in the dashboard is no longer in version control, and a
 * deploy will not update it. An operator who would rather keep the guide in
 * Markdown should write files into these nine directories instead and never
 * run this seeder -- the files win nothing and lose nothing by its absence.
 *
 * ---------------------------------------------------------------------------
 * Re-running it
 * ---------------------------------------------------------------------------
 *
 * Safe. Each page is created only if its path is absent, so a second run
 * restores whatever was deleted and leaves every edit alone. That is the right
 * way round for content: a seeder that overwrote would be a seeder that ate an
 * afternoon's writing the first time somebody ran it twice.
 */
final class WikiContentSeeder extends Seeder
{
    /**
     * The nine sections, each with the one page that opens it.
     *
     * `section` matches a directory under resources/wiki, and the pair is what
     * the page is addressed by: /wiki/<section>/overview.
     *
     * @var list<array{section: string, title: string, summary: string, body: string}>
     */
    private const PAGES = [
        [
            'section' => 'getting-started',
            'title' => 'Getting Started',
            'summary' => 'What stands between a new player and their first login.',
            'body' => <<<'MARKDOWN'
                Everything a new player needs, in the order they need it.

                ## What to write here

                - What this server is, in a sentence or two: the episode, the
                  rates, and who it is for.
                - The account they need, and whether one account covers both
                  this website and the game.
                - The client they need, with a link to
                  [Game Access](/wiki/game-access/overview).
                - What to do in the first hour, so nobody logs in and stands
                  in Prontera wondering.

                Replace this page once the above is written. Until then it is
                a note to the operator, not a guide to the server.
                MARKDOWN,
        ],
        [
            'section' => 'website-features',
            'title' => 'Website Features',
            'summary' => 'What this site does for an account that the game cannot.',
            'body' => <<<'MARKDOWN'
                This control panel is where an account is managed from.

                ## What to write here

                - Signing in, and what to do about a forgotten password.
                - Changing an email address or a password, and the
                  confirmation step each one takes.
                - The character list: what it shows, and what can be changed
                  from it.
                - The rankings, and how often the figures behind them refresh.
                - The item and monster databases, and what they are good for.

                Most of this is the same on every panel, so it is the one
                section that can be written before the server is finished.
                MARKDOWN,
        ],
        [
            'section' => 'game-access',
            'title' => 'Game Access',
            'summary' => 'The download, the patcher, and the address to connect to.',
            'body' => <<<'MARKDOWN'
                How to get the game running and pointed at this server.

                ## What to write here

                - The full client download, its size, and a mirror in case the
                  first one is slow.
                - The patcher: what it does, and what to do when it refuses to
                  finish.
                - The server address and port, written out so it can be copied.
                - The usual first-run problems on each platform, and the fix
                  for each.

                Keep the addresses here and nowhere else. A port written into
                four pages is a port that gets changed in one of them.
                MARKDOWN,
        ],
        [
            'section' => 'server-guide',
            'title' => 'Server Guide',
            'summary' => 'Rates, rules, and the commands worth knowing.',
            'body' => <<<'MARKDOWN'
                How this server is set up, and how it expects to be played.

                ## What to write here

                - The rates: base, job, drops, cards, and anything that differs
                  by level bracket.
                - The rules, and what breaking each one costs.
                - The commands a player can use, and the ones reserved for
                  staff.
                - Party, guild and War of Emperium settings, where they differ
                  from stock.

                The rates can also be set in `config/wiki.php`, which puts them
                beside the search box on the wiki's front page.
                MARKDOWN,
        ],
        [
            'section' => 'events',
            'title' => 'Events',
            'summary' => 'What runs on a timer, what the staff run by hand, and when.',
            'body' => <<<'MARKDOWN'
                What happens on a schedule, and what happens when staff are
                about.

                ## What to write here

                - The automated events: what each one is, where it starts, and
                  how often.
                - A timetable, with the timezone written out. "20:00" means
                  nothing to a player eight hours away.
                - Staff-run events, and how they are announced.
                - Seasonal events, and the dates they run between.

                A timetable is the single most re-read page on most servers'
                wikis. It is worth keeping accurate.
                MARKDOWN,
        ],
        [
            'section' => 'game-features',
            'title' => 'Game Features',
            'summary' => 'The systems this server adds on top of a stock one.',
            'body' => <<<'MARKDOWN'
                Everything built on top of a stock server.

                ## What to write here

                - Custom NPCs: where they stand and what they are for.
                - Instances, and how to start one.
                - Any progression this server adds that a returning player
                  would not recognise.
                - Systems borrowed from a later episode than the one this
                  server otherwise runs.

                A player who knows Ragnarok still does not know this server.
                This is the section that tells them the difference.
                MARKDOWN,
        ],
        [
            'section' => 'modifications',
            'title' => 'Modifications',
            'summary' => 'What has been changed from stock, and what was added for comfort.',
            'body' => <<<'MARKDOWN'
                Where this server departs from a stock one.

                ## What to write here

                - Client-side modifications, and whether the patcher applies
                  them automatically.
                - Server-side changes to skills, items or monsters.
                - Quality-of-life additions: stackable buffs, a wider stash,
                  faster rebirth, and the rest.
                - Anything removed, which matters as much as anything added.

                Keep this honest and complete. A difference a player discovers
                for themselves at level 80 costs more goodwill than one written
                down here.
                MARKDOWN,
        ],
        [
            'section' => 'other-informations',
            'title' => 'Other Informations',
            'summary' => 'Donations, contact, policies, and whatever did not fit elsewhere.',
            'body' => <<<'MARKDOWN'
                The pages that belong somewhere but not under another heading.

                ## What to write here

                - Donations: what is offered, what it costs, and the promise
                  that none of it is required to play.
                - How to reach the staff, and how long an answer usually takes.
                - The rules on multiple accounts, botting and trading.
                - Privacy and data: what is kept, and for how long.

                When this section grows past a handful of pages, most of them
                want promoting to a section of their own.
                MARKDOWN,
        ],
        [
            'section' => 'server-updates',
            'title' => 'Server Updates',
            'summary' => 'Patch notes and announcements, newest first.',
            'body' => <<<'MARKDOWN'
                What changed, and when.

                ## What to write here

                One entry per update, newest at the top, each with its date.
                Say what changed in a player's words rather than a
                changelog's: "Hunters hit harder below level 60" is read, and
                "adjusted `bonus bDamageRatio`" is not.

                Keep the old entries. A player coming back after a year reads
                down this page to find out what they missed, and a page that
                only ever shows the latest patch cannot answer that.
                MARKDOWN,
        ],
    ];

    public function run(): void
    {
        foreach (self::PAGES as $page) {
            $section = $page['section'];

            WikiPage::query()->firstOrCreate(
                ['path' => $section.'/overview'],
                [
                    'category' => $section,
                    'slug' => 'overview',
                    'title' => $page['title'],
                    'summary' => $page['summary'],
                    // One page per section for now, so the position only
                    // matters once an operator adds a second.
                    'sort_order' => 1,
                    'body' => $page['body']."\n",
                    'hidden' => false,
                    // Nobody wrote it, so no account owns it.
                    'updated_by' => null,
                ],
            );
        }
    }
}
