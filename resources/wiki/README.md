# The wiki

The pages under here are served at `/wiki`. One directory per section, one
Markdown file per page, and the names on disk are the URL:

    getting-started/creating-an-account.md  ->  /wiki/getting-started/creating-an-account

## What ships, and where it lives

Nine sections, and nothing else on disk:

    getting-started/      website-features/     game-access/
    server-guide/         events/               game-features/
    modifications/        other-informations/   server-updates/

Each is a single `_category.md` giving the section its title, blurb, icon and
position. That file is the only place those four can live — `wiki_pages` has
no column for any of them — so a section renamed or reordered is edited here.

The page behind each section is **a database row, not a file**, written by
`database/seeders/WikiContentSeeder.php`:

    php artisan db:seed --class=WikiContentSeeder

Rows because rows are what the dashboard can edit: an operator whose only
access is a browser rewrites all nine at `/admin/wiki` without touching the
filesystem. The cost is the one the seeder's own notes spell out — a page
edited there is no longer in version control, and a deploy will not update it.

Re-running the seeder is safe. It creates a page only where none exists, so it
restores anything deleted and leaves every edit alone.

## Making it yours

**What ships is a starting point, not your guide.** Each of the nine pages
says what belongs in its section rather than claiming anything about your
server, because the panel cannot know your rates, your events or your
commands. Until you write those, the wiki is an outline.

Two ways to fill it in, and they mix freely:

- **In the browser**, at `/admin/wiki`. Quickest, and the reason the pages are
  rows.
- **On disk**, by writing `.md` files into these directories. A file is read
  exactly as before, and a section can hold both — a row and a file are
  matched on their path, so a file is only ever hidden by a row that shares
  its name.

Prefer files if you want the guide reviewed in pull requests and restored by a
deploy. Prefer the dashboard if you want to fix a typo from your phone.

One thing is referenced by code: `config/wiki.php` lists a handful of paths
under `popular` for the chips above the search box. A path that no longer
resolves is dropped rather than rendered dead, so pruning a page cannot break
the page it was linked from — but the chip quietly disappears, so update the
list when you rename something.

Full guide, including the front matter each file carries:
[`docs/WIKI.md`](../../docs/WIKI.md).

This file is not a page. Only directories are read, so anything sitting at the
top level — this README, a draft, a folder of images — is left alone.
