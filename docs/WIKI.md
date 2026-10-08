# The wiki

The panel serves a player guide at `/wiki`, written as Markdown files in
`resources/wiki/`. This is how to write one.

The pages that ship are a starting point and are meant to be replaced. They
describe what the control panel does, because that is the only thing the panel
can know about your server; everything that makes your server yours is for you
to write.

## Why files rather than a database table

The panel already has a CMS for one-off pages — `cp_cmspages`, served at
`/api/pages` — and the wiki deliberately does not use it. A wiki is not a
handful of pages; it is a body of writing that gets revised for years, by
several people, and the things that matter about it are the things a
filesystem gives for free:

- it is in version control, so a wrong edit is a revert rather than an
  apology, and "who changed the rates page" has an answer;
- it can be written in an editor, reviewed in a pull request, and deployed
  with everything else, instead of being typed into a textarea;
- it survives a database restore from before the page was written.

The cost is that adding a page needs filesystem access. An operator whose only
access is a web browser is better served by `cp_cmspages`, which is still
there and is not going away.

## Layout

```
resources/wiki/
  getting-started/
    _category.md                 the section's own title and description
    welcome.md                   /wiki/getting-started/welcome
    creating-an-account.md       /wiki/getting-started/creating-an-account
  help/
    _category.md
    faq.md                       /wiki/help/faq
```

One directory per section, one Markdown file per page, exactly two levels
deep. A file at the top level is not a page, and neither is a file whose name
begins with an underscore — that is where `_category.md` and any drafts you do
not want served can sit.

The names on disk are the URL, lowercased, with spaces and punctuation turned
into dashes: `Creating an Account.md` is served at `creating-an-account`.
**Renaming a file changes its URL** and breaks whatever linked to it, so the
names are the one thing worth choosing carefully up front.

## Front matter

Each file may open with a metadata block. Everything in it is optional.

```markdown
---
title: Creating an account
summary: What you need, and what happens after you register.
order: 2
updated: 2026-10-07
---

The body, in Markdown.
```

| Field | Means |
| --- | --- |
| `title` | The page's heading and its name in every list. Defaults to the filename. |
| `summary` | One sentence, shown under the title, in the section card and as the search snippet. |
| `order` | Where it sits in its section. A page without one sorts after every page that has one. |
| `updated` | The revision date. Defaults to the file's own modification time, which is usually right. |

`_category.md` takes `title`, `description`, `icon` and `order`, and its body
is ignored. A section with no `_category.md` is titled from its directory name
and sorts after the ones that declare an order. A section with no pages in it
is not drawn at all.

`icon` is one of `book`, `play`, `globe`, `help`, `sword`, `shield`, `coins`,
`key`, `calendar`, `map`, `users`. Anything else falls back to `book`.

The parser reads one `key: value` per line and nothing else — no nesting, no
lists, no multi-line values. A line it cannot read is skipped rather than
guessed at. See `App\Support\Content\FrontMatter`.

## Writing a page

The body is Markdown, rendered by the same `ContentRenderer` the news and the
CMS pages go through: **raw HTML is stripped**, and `javascript:` and `data:`
links are refused. Headings, lists, tables, code, images, emphasis and links
all work. See [MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md) (D20) for why
the rule is uniform across all three content sources.

Two conventions worth keeping:

- **Start sections at `##`.** The page's title comes from the front matter and
  is rendered above the body, so an `#` in the text is a second title. Only
  `##` and `###` get anchors and appear in the contents sidebar.
- **Link internally with an absolute path** — `[rates](/wiki/getting-started/rates)`,
  `[the downloads page](/downloads)`. Those navigate within the application
  instead of reloading it. A link to another site opens in a new tab on its
  own; you do not need to say so.

## Configuration

`config/wiki.php`, and all of it optional:

| Setting | Default | Means |
| --- | --- | --- |
| `enabled` | `true` | `WIKI_ENABLED=false` serves 404 from every wiki endpoint. |
| `path` | `resources/wiki` | Where the files are. Relative paths resolve from the project root. |
| `cache_seconds` | `0` | Off, so an edit appears on the next reload. Set it on a large wiki. |
| `popular` | four starter pages | The shortcut chips above the search box. A path that no longer resolves is dropped. |
| `recent_limit` | `6` | How many pages the "recently updated" list holds. |
| `rates` | `[]` | The figures beside the search box. Absent from the page until you fill it in. |
| `search.*` | — | Minimum term length, result limit, snippet length. |

Rates are a label, a figure and an optional note. The figure is free text, so
`15x`, `0.05%` and `Disabled` are all sayable:

```php
'rates' => [
    ['label' => 'Base EXP', 'value' => '15x'],
    ['label' => 'Job EXP', 'value' => '15x'],
    ['label' => 'Drops', 'value' => '3x', 'note' => 'Normal items'],
    ['label' => 'MvP cards', 'value' => '1x'],
],
```

Nothing is shipped filled in: the panel cannot read rates out of rAthena's
configuration, and a default would be this panel making a claim about your
server.

## Keeping the guide somewhere else

Set `GAME_WIKI_URL` and the navigation points at it instead — a GitBook, a
forum, a Notion. A configured URL wins over the internal route, the same way
`GAME_DOWNLOADS_URL` does, so the two never both appear. The pages under
`/wiki` stay reachable but unlinked; `WIKI_ENABLED=false` removes them.

## Endpoints

Three, all public, and all read-only. There is no write half: the way to add a
page is a commit.

| | |
| --- | --- |
| `GET /api/wiki` | Sections, their pages, what changed recently, the shortcuts and the rates |
| `GET /api/wiki/{section}/{page}` | One page, rendered, with its headings and its neighbours |
| `GET /api/wiki/search?q=` | Pages matching a term, title matches first |

See [API.md](API.md) for the shapes.

## Notes on how it is built

- **No filename is ever built from a request.** The directory is scanned,
  which produces the set of pages that exist, and a requested path is matched
  against that set — so a traversal is a 404 before any file is opened. The
  route constraint refuses anything that is not two plain slugs before that
  even runs.
- **Reading order runs across sections**, so "next" from the last page of one
  section goes to the first page of the next rather than to a dead end.
- **The index is one request.** The landing page and every article's sidebar
  are the same question asked of the same directory, so the client fetches it
  once and keeps it for the rest of the page load.
- **The wiki's chrome is translated; the pages are not.** A server writing its
  guide in two languages writes two sets of files — a translation table is the
  wrong shape for a paragraph.
