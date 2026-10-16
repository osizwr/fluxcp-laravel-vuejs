<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A snapshot, written by `php artisan panel:dump-seed`.
 *
 * 14 rows from cp_cmsnews, wiki_pages, as they stood on Fri, Oct 16, 2026 4:07 AM.
 *
 * Rows are written with upsert where the table has a single-column
 * primary key, and insertOrIgnore where it does not, so running this
 * twice is not two copies. It restores content into a database that
 * already has the tables -- run the migrations and
 * `panel:install-schema` first.
 *
 * Generated. Edit the database and dump it again rather than editing
 * this by hand.
 */
final class MySeeder extends Seeder
{
    /**
     * The connection this was taken from.
     *
     * Resolved by name at run time, so a machine whose server groups
     * are configured differently reads its own.
     */
    private const CONNECTION = 'mysql';

    public function run(): void
    {
        $rows = [
            ['id' => 1, 'title' => 'asd', 'body' => 'asd', 'link' => '', 'author' => 'asd', 'created' => '2026-10-10 07:20:05', 'modified' => '2026-10-10 07:20:05'],
            ['id' => 2, 'title' => 'BLAIZE', 'body' => 'BLAIZE', 'link' => '', 'author' => 'BLAIZE', 'created' => '2026-10-10 07:20:18', 'modified' => '2026-10-10 07:20:18'],
            ['id' => 3, 'title' => 'BLAIZE', 'body' => 'BLAIZE', 'link' => '', 'author' => 'impresence', 'created' => '2026-10-10 17:27:59', 'modified' => '2026-10-10 17:27:59'],
            ['id' => 4, 'title' => 'BLAIZE', 'body' => 'BLAIZE', 'link' => '', 'author' => 'impresence', 'created' => '2026-10-10 17:28:06', 'modified' => '2026-10-10 17:28:06'],
        ];

        DB::connection(self::CONNECTION)->table('cp_cmsnews')
            ->upsert($rows, ['id']);
        $rows = [
            ['path' => 'events/overview', 'category' => 'events', 'slug' => 'overview', 'title' => 'Events', 'summary' => 'What runs on a timer, what the staff run by hand, and when.', 'sort_order' => 1, 'body' => "What happens on a schedule, and what happens when staff are\nabout.\n\n## What to write here\n\n- The automated events: what each one is, where it starts, and\n  how often.\n- A timetable, with the timezone written out. \"20:00\" means\n  nothing to a player eight hours away.\n- Staff-run events, and how they are announced.\n- Seasonal events, and the dates they run between.\n\nA timetable is the single most re-read page on most servers'\nwikis. It is worth keeping accurate.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
            ['path' => 'game-access/overview', 'category' => 'game-access', 'slug' => 'overview', 'title' => 'Game Access', 'summary' => 'The download, the patcher, and the address to connect to.', 'sort_order' => 1, 'body' => "How to get the game running and pointed at this server.\n\n## What to write here\n\n- The full client download, its size, and a mirror in case the\n  first one is slow.\n- The patcher: what it does, and what to do when it refuses to\n  finish.\n- The server address and port, written out so it can be copied.\n- The usual first-run problems on each platform, and the fix\n  for each.\n\nKeep the addresses here and nowhere else. A port written into\nfour pages is a port that gets changed in one of them.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
            ['path' => 'game-features/overview', 'category' => 'game-features', 'slug' => 'overview', 'title' => 'Game Features', 'summary' => 'The systems this server adds on top of a stock one.', 'sort_order' => 1, 'body' => "Everything built on top of a stock server.\n\n## What to write here\n\n- Custom NPCs: where they stand and what they are for.\n- Instances, and how to start one.\n- Any progression this server adds that a returning player\n  would not recognise.\n- Systems borrowed from a later episode than the one this\n  server otherwise runs.\n\nA player who knows Ragnarok still does not know this server.\nThis is the section that tells them the difference.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
            ['path' => 'getting-started/overview', 'category' => 'getting-started', 'slug' => 'overview', 'title' => 'Getting Started', 'summary' => 'What stands between a new player and their first login.', 'sort_order' => 1, 'body' => "Everything a new player needs, in the order they need it.\n\n## What to write here\n\n- What this server is, in a sentence or two: the episode, the\n  rates, and who it is for.\n- The account they need, and whether one account covers both\n  this website and the game.\n- The client they need, with a link to\n  [Game Access](/wiki/game-access/overview).\n- What to do in the first hour, so nobody logs in and stands\n  in Prontera wondering.\n\nReplace this page once the above is written. Until then it is\na note to the operator, not a guide to the server.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
            ['path' => 'getting-started/test', 'category' => 'getting-started', 'slug' => 'test', 'title' => 'TEST', 'summary' => 'TEST', 'sort_order' => null, 'body' => 'test', 'hidden' => 0, 'updated_by' => 2000002, 'created_at' => '2026-10-10 19:22:30', 'updated_at' => '2026-10-10 19:22:30'],
            ['path' => 'modifications/overview', 'category' => 'modifications', 'slug' => 'overview', 'title' => 'Modifications', 'summary' => 'What has been changed from stock, and what was added for comfort.', 'sort_order' => 1, 'body' => "Where this server departs from a stock one.\n\n## What to write here\n\n- Client-side modifications, and whether the patcher applies\n  them automatically.\n- Server-side changes to skills, items or monsters.\n- Quality-of-life additions: stackable buffs, a wider stash,\n  faster rebirth, and the rest.\n- Anything removed, which matters as much as anything added.\n\nKeep this honest and complete. A difference a player discovers\nfor themselves at level 80 costs more goodwill than one written\ndown here.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
            ['path' => 'other-informations/overview', 'category' => 'other-informations', 'slug' => 'overview', 'title' => 'Other Informations', 'summary' => 'Donations, contact, policies, and whatever did not fit elsewhere.', 'sort_order' => 1, 'body' => "The pages that belong somewhere but not under another heading.\n\n## What to write here\n\n- Donations: what is offered, what it costs, and the promise\n  that none of it is required to play.\n- How to reach the staff, and how long an answer usually takes.\n- The rules on multiple accounts, botting and trading.\n- Privacy and data: what is kept, and for how long.\n\nWhen this section grows past a handful of pages, most of them\nwant promoting to a section of their own.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
            ['path' => 'server-guide/overview', 'category' => 'server-guide', 'slug' => 'overview', 'title' => 'Server Guide', 'summary' => 'Rates, rules, and the commands worth knowing.', 'sort_order' => 1, 'body' => "How this server is set up, and how it expects to be played.\n\n## What to write here\n\n- The rates: base, job, drops, cards, and anything that differs\n  by level bracket.\n- The rules, and what breaking each one costs.\n- The commands a player can use, and the ones reserved for\n  staff.\n- Party, guild and War of Emperium settings, where they differ\n  from stock.\n\nThe rates can also be set in `config/wiki.php`, which puts them\nbeside the search box on the wiki's front page.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
            ['path' => 'server-updates/overview', 'category' => 'server-updates', 'slug' => 'overview', 'title' => 'Server Updates', 'summary' => 'Patch notes and announcements, newest first.', 'sort_order' => 1, 'body' => "What changed, and when.\n\n## What to write here\n\nOne entry per update, newest at the top, each with its date.\nSay what changed in a player's words rather than a\nchangelog's: \"Hunters hit harder below level 60\" is read, and\n\"adjusted `bonus bDamageRatio`\" is not.\n\nKeep the old entries. A player coming back after a year reads\ndown this page to find out what they missed, and a page that\nonly ever shows the latest patch cannot answer that.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
            ['path' => 'website-features/overview', 'category' => 'website-features', 'slug' => 'overview', 'title' => 'Website Features', 'summary' => 'What this site does for an account that the game cannot.', 'sort_order' => 1, 'body' => "This control panel is where an account is managed from.\n\n## What to write here\n\n- Signing in, and what to do about a forgotten password.\n- Changing an email address or a password, and the\n  confirmation step each one takes.\n- The character list: what it shows, and what can be changed\n  from it.\n- The rankings, and how often the figures behind them refresh.\n- The item and monster databases, and what they are good for.\n\nMost of this is the same on every panel, so it is the one\nsection that can be written before the server is finished.\n", 'hidden' => 0, 'updated_by' => null, 'created_at' => '2026-10-10 16:32:25', 'updated_at' => '2026-10-10 16:32:25'],
        ];

        DB::connection(self::CONNECTION)->table('wiki_pages')
            ->upsert($rows, ['path']);
    }
}
