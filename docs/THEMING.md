# Theming

How the panel's appearance is separated from what it does, and how to build a
skin of your own.

A theme here controls more than colour. It has three layers, in increasing
order of how much it takes over:

| Layer | What it changes | What it costs |
| --- | --- | --- |
| **Design tokens** | Colours, type, geometry across the whole application | One stylesheet |
| **Blocks** | How an individual section looks and is structured | One component per section replaced |
| **Page composition** | Which sections a page has, and in what order | A list in `theme.json` |

The rule the whole system rests on: **a theme is presentation**. It may restyle
and restructure the interface. It may not query a database, call an API,
authenticate anybody, or decide what someone is allowed to do. The same backend
serves every theme, and switching theme cannot change a single API response —
there is a test asserting exactly that.

---

## 1. Switching theme

```env
APP_THEME=fantasy
```

That is the whole operation. The theme is resolved per request, so the change
takes effect on the next page load.

Three themes ship:

| Slug | What it is |
| --- | --- |
| `fantasy` | The default. Dark, warm, antique gold — an adventurer's guild ledger. |
| `slate` | A cool, light-first skin that ships **nothing but a palette**, as a worked example. |
| `yatagarasu` | Dark fantasy in gold and blood on black, art-directed for a game's front page. The worked example at the other end of the scale: thirteen blocks, two of them its own, and a composed home page of ten sections. |

```bash
php artisan theme:list     # what is installed, and which is active
```

### The one thing that needs a rebuild

Switching between themes that were present at the last `npm run build` needs
only `.env`. **Adding a new theme directory needs `npm run build`**, because
Vite has to have seen the files to bundle them.

This is a deliberate trade rather than a limitation that was missed. The
alternative — resolving theme files over HTTP at runtime — would mean shipping
an interpreter for Vue templates to the browser and giving up on bundling. So
every installed theme is built, and the server picks one per request:

```
.env  ──►  config/theme.php  ──►  ThemeService  ──►  Blade links that theme's CSS
                                        │
                                        └─────────►  bootstrap payload  ──►  Vue resolver
```

---

## 2. Branding is separate from theming

Two different questions, two different files:

| | Question | Where |
| --- | --- | --- |
| **Game** | What server is this? | `config/game.php` |
| **Theme** | How does it look? | `config/theme.php` |

```env
GAME_NAME="My Ragnarok Online"
GAME_SHORT_NAME="MRO"
GAME_DESCRIPTION="Fantasy MMORPG"
APP_THEME=fantasy
```

Renaming the server does not touch a theme, and installing a theme does not
rename the server. A component reads the name from `useGame()` and never writes
it in — there is a test that fails if any `.vue` file hardcodes it.

`GAME_NAME` is not `APP_NAME`. `APP_NAME` stays Laravel's internal application
name, used for things like the mail "from" name; `GAME_NAME` is the brand
players see.

---

## 3. Theme structure

```
resources/themes/fantasy/
├── theme.json          required — manifest, layout roles, page compositions
├── styles/
│   ├── theme.css       the Vite entrypoint; imports the others
│   ├── variables.css   the palette
│   ├── components.css  how the application's own components look
│   └── blocks.css      how this theme's sections are decorated
├── blocks/             override page sections (hero, navbar, rankings, …)
├── layouts/            override AppLayout, PublicLayout, AuthLayout
├── pages/              override a whole page
├── components/         override core components, or add theme-only ones
└── assets/             images, icons, fonts the theme owns
```

Only `theme.json` is required. A theme consisting of nothing but `theme.json`
and `styles/theme.css` is valid and useful — see `slate`.

`yatagarasu` is the opposite extreme to `slate`, and worth reading if you are
building something ambitious: it replaces all eleven core blocks, adds two of
its own (`promo-banner` and `lore-section` — a theme's blocks resolve by name
whether or not core has one), and composes a ten-section front page. It still
contains no data fetching, because every section takes its figures from the
same composables in `resources/js/blocks/data.ts` that every other theme uses.

It also ships no artwork. Each image is a slot that requests its path and
falls back to a labelled placeholder, so supplying the art is dropping files
into `public/images/` rather than editing the theme. The expected paths are
listed in `resources/themes/README.md`.

The `fantasy` theme overrides eight blocks and inherits three from core
(`statistics`, `class-showcase`, `feature-grid`), which is the fallback system
working in production rather than only in a test.

### theme.json

```json
{
    "name": "Fantasy",
    "version": "1.0.0",
    "author": "",
    "description": "A dark, warm skin in the manner of an adventurer's ledger.",
    "supports": { "dark_mode": true, "light_mode": true, "mobile": true },
    "default_appearance": "dark",

    "layouts": {
        "public": "PublicLayout",
        "app": "AppLayout",
        "auth": "AuthLayout"
    },

    "pages": {
        "home": {
            "layout": "public",
            "blocks": [
                "hero",
                { "block": "server-status", "props": { "detailed": true } },
                "statistics",
                { "block": "ranking-showcase", "props": { "ladder": "level", "limit": 5 } },
                "call-to-action"
            ]
        }
    }
}
```

| Field | |
| --- | --- |
| `name`, `version` | **Required.** A theme missing either is ignored and logged. |
| `author` | Free text. Empty in the shipped themes because this project's copyright holder has not been set — see [NOTICE](../NOTICE). |
| `description` | Shown in `theme:list`. |
| `supports` | Advisory flags, readable from the client via `useGame().supports()`. |
| `default_appearance` | `light`, `dark`, or omitted to follow the visitor's OS. |
| `layouts` | Layout component per role. Optional; each role has a core default. |
| `pages` | Page compositions, keyed by route name. Optional. |

There is no `slug` field to set: **the directory name is the slug.** A manifest
declaring a different one is rejected, because the directory name is what
`APP_THEME` names and what every override path is built from.

### `default_appearance`

A theme art-directed for one appearance should say so. Without it, a visitor
whose system prefers light sees a dark-first theme at its worst on their first
visit, and the appearance toggle disagrees with what is on screen.

It is a default, not a lock. The visitor's own choice is stored and wins; a
theme's default is deliberately *not* persisted, so it does not outlive the
theme that suggested it.

---

## 4. Styling: the cheap path

Every core component reads **semantic CSS custom properties** rather than a
palette. Redefine them and the whole panel changes — masthead, tables, buttons,
forms, status pills, empty states — without overriding one component.

That is all `slate` does.

```css
/* resources/themes/my-theme/styles/theme.css */

:root[data-theme-slug='my-theme'] {
    --surface-page: oklch(0.97 0.004 250);
    --surface-raised: oklch(1 0 0);
    --surface-sunken: oklch(0.95 0.005 250);
    --surface-hover: oklch(0.94 0.007 250);

    --border-subtle: oklch(0.9 0.008 250);
    --border-strong: oklch(0.82 0.012 250);

    --text-primary: oklch(0.22 0.015 255);
    --text-secondary: oklch(0.48 0.015 255);
    --text-muted: oklch(0.6 0.014 255);

    --color-accent-300: …;  /* through 700 */
    --color-accent-600: …;  /* primary buttons, active nav */

    --color-up: …;          /* online */
    --color-down: …;        /* offline */
    --color-warn: …;
    --status-up-bg: …;
    --status-down-bg: …;
    --status-warn-bg: …;

    --radius-panel: 0.5rem;
    --font-display: 'Your Display Font', serif;
}
```

### Always scope to the slug

```css
:root[data-theme-slug='my-theme'] { … }     /* yes */
:root { … }                                  /* no */
```

Two reasons. It wins on **specificity** (0,1,1 against the core's 0,0,1) so it
does not matter which stylesheet the browser applies last — and stylesheet
order here genuinely is not guaranteed, since the core CSS comes from the
bundle and the theme's from its own `<link>`. And it means installing a second
theme cannot inherit your rules.

### Dark and light

```css
:root[data-theme-slug='my-theme'] { /* your default appearance */ }
:root[data-theme-slug='my-theme'][data-appearance='light'] { /* the other one */ }
```

`data-appearance` is light/dark. `data-theme-slug` is the skin. They are
different words on purpose.

---

## 5. Overrides: the structural path

When restyling is not enough, drop a file with a matching name into the
matching directory:

```
resources/themes/fantasy/pages/HomePage.vue      overrides
resources/js/pages/HomePage.vue

resources/themes/fantasy/layouts/AppLayout.vue   overrides
resources/js/layouts/AppLayout.vue
```

No registration, no configuration, and no condition anywhere on your theme's
name. If the active theme provides the file, it is used; if not, the core one
is. That is the entire mechanism, in `resources/js/theme/resolve.ts`.

### What can be overridden

| Kind | Directory | Core location |
| --- | --- | --- |
| Pages | `pages/` | `resources/js/pages/` |
| Layouts | `layouts/` | `resources/js/layouts/` |
| Components | `components/` | `resources/js/components/` |

Current core pages: `HomePage`, `LoginPage`, `AccountPage`, `CharactersPage`,
`OnlinePage`, `RankingsPage`, `NotFoundPage`. Layouts: `AppLayout`,
`AuthLayout`.

A route chooses its layout with `meta.layout`; the sign-in route asks for
`AuthLayout`.

### Importing core code

Use the `@` alias rather than counting directories:

```ts
import AppButton from '@/components/ui/AppButton.vue'
import { useGame } from '@/composables/useGame'
import { useShell } from '@/composables/useShell'
import { useServerStore } from '@/stores/server'
```

### Logic stays in core

A theme's layout should not reimplement navigation. `useShell()` provides the
link list, active-route matching and the sign-out sequence; a theme consumes it
and decides only how it looks:

```ts
const { links, isActive, menuOpen, signingOut, signOut } = useShell()
```

Without that, every theme would copy the sign-out sequence and the fourth one
would be the theme that forgot to clear the session properly.

### Theme-only components

A file in `components/` that does not match a core component's name is simply a
component your theme owns, imported directly. `fantasy/components/GameMark.vue`
is one.

---

## 6. Blocks

A **block** is a named section of a page: the hero, the server status panel, a
ranking ladder, the footer. Blocks are the unit a theme replaces when it wants
a section to look or work differently.

```
resources/themes/fantasy/blocks/Hero.vue      overrides
resources/js/blocks/Hero.vue
```

Resolution is the same three-step fallback as everything else:

```
active theme's blocks/<Name>.vue   ->   the theme's own
resources/js/blocks/<Name>.vue     ->   the core default
neither                            ->   skipped, and verify:themes fails
```

That fallback is the point. A new theme does not have to reimplement eleven
blocks to change three of them.

### The core blocks

Fourteen ship, in `resources/js/blocks/`:

| Block name | Component | Shows |
| --- | --- | --- |
| `announcement-bar` | `AnnouncementBar.vue` | The operator's notice, if there is one |
| `navbar` | `Navbar.vue` | Masthead, navigation, session, appearance toggle |
| `hero` | `Hero.vue` | Branding, tagline, calls to action, live player count |
| `server-status` | `ServerStatus.vue` | Login/character/map state, players online |
| `statistics` | `Statistics.vue` | Accounts, characters, guilds, players online |
| `class-showcase` | `ClassShowcase.vue` | Characters per job class |
| `ranking-showcase` | `RankingShowcase.vue` | A short ladder with a link onward |
| `news-section` | `NewsSection.vue` | Recent news |
| `feature-grid` | `FeatureGrid.vue` | Operator-configured features |
| `download-clients` | `DownloadClients.vue` | The packages on offer, and their mirrors |
| `system-requirements` | `SystemRequirements.vue` | What a machine needs, one tab per set |
| `install-guide` | `InstallGuide.vue` | The numbered steps after a download |
| `call-to-action` | `CallToAction.vue` | A closing action |
| `footer` | `Footer.vue` | Branding, navigation, configured links |

Names are kebab-case in configuration and PascalCase as files. That translation
happens in one place, `blockFileName()` in `resources/js/theme/blocks.ts`.

### Writing a block

A block has two kinds of input, deliberately separate:

```ts
// props -- presentation choices the page composition makes
const props = withDefaults(defineProps<RankingProps>(), { ladder: 'level', limit: 5 })

// data -- application state, from a core composable. Never fetched here.
const ranking = useRankingData(props.ladder, props.limit)
```

Every contract lives in `resources/js/blocks/contracts.ts` and every data
composable in `resources/js/blocks/data.ts`. **A block never calls the API.**
That is what keeps themes free of data access, and it is enforced: a test
scans every theme file for `fetch(`, `DB::`, `Hash::` and `Gate::`.

The contracts are the one part of the theme system that should move slowly.
Adding a field changes every theme's expectations, and a theme inventing its
own shape would mean the backend was no longer independent of the presentation.

### Blocks are reusable

The same block appears in more than one place. `server-status` is on the front
page and could go on a dashboard; `navbar` and `footer` are used by both the
public and application layouts. Nothing about a block ties it to one page.

---

## 7. Page composition

A theme can declare what a page is made of, rather than overriding the whole
page:

```json
"pages": {
    "home": {
        "layout": "public",
        "blocks": [
            "hero",
            { "block": "server-status", "props": { "detailed": true } },
            "statistics",
            { "block": "ranking-showcase", "props": { "ladder": "zeny", "limit": 10 } },
            "call-to-action"
        ]
    }
}
```

Reordering that list reorders the page. Removing an entry removes a section.
Neither touches a component.

Keys are **route names**, so a theme composes `home` without knowing a file
path. `downloads` is the other page built this way out of the box: it is a
heading plus `download-clients`, `system-requirements` and `install-guide`, so a
theme that wants the packages somewhere else in the order, or wants to drop the
specification tables, composes those three rather than overriding a page. A block with no options may be a bare string; `{ "block": …, "props": … }`
is for when it has some.

### How a page is chosen

For each route, in order:

1. the theme's `pages/<Name>.vue` — a hand-built page, full control
2. a composition in `theme.json` — declarative, a list of blocks
3. the core `pages/<Name>.vue` — the application's own

So a theme can take a page over entirely, rearrange it from configuration, or
leave it alone. `slate` declares no compositions and gets core pages
throughout; `fantasy` composes its front page from eight blocks.

The renderer is `resources/js/pages/ComposedPage.vue`, and it is a loop. It
knows nothing about any block, which is why adding one to a page never touches
it.

### Layout roles

`layout` names a **role**, not a component:

| Role | Core default | For |
| --- | --- | --- |
| `public` | `PublicLayout` | Full-bleed landing pages |
| `app` | `AppLayout` | Utility pages, content in a column |
| `auth` | `AuthLayout` | Sign-in and friends; no navigation |

A theme's `layouts` map may point a role at a differently named component. The
announcement bar, navbar and footer live in the layouts rather than in every
page's block list — repeating three entries on every page would be noise, not
control.

### Where navbar and footer live

They are blocks, so a theme replaces them like any other. They are *composed by
the layout* rather than listed per page. Before this they were written inline
in each layout, and the two copies drifted apart.

### Not everything is configuration

Compositions exist for page structure. They are deliberately not a page
builder: no conditionals, no slots, no nesting, no per-block visibility rules.
A section that needs real logic should be a block, where it is ordinary Vue
with types and a test, rather than an expression language in JSON.

---

## 8. Data and realtime

Themes **consume** data. They never fetch it.

Blocks use the composables in `resources/js/blocks/data.ts`, each returning one
of the contracts in `contracts.ts`:

```ts
useAnnouncement()        // the operator's notice, and dismissal
useHeroData(props)       // branding plus live status
useServerStatusData()    // processes, players, War of Emperium
useStatisticsData()      // accounts, characters, guilds, players online
useClassShowcaseData(n)  // characters per job class
useRankingData(l, n)     // a ladder
useNewsData(n)           // recent articles
useFeatureData()         // operator-configured features
useCallToActionData()    // session-aware actions
```

Outside a block, the stores are available directly:

```ts
const servers = useServerStore()   // server status, kept current for you
const auth = useAuthStore()        // the signed-in account
const site = useSiteStore()        // statistics, classes, news
```

Server status is already fed by the Reverb broadcast with a polling fallback,
started once in `App.vue`. A theme displaying live data just reads the store:

```vue
<p>{{ servers.playersOnline.toLocaleString() }} online</p>
```

**Do not open a second websocket.** There is one broadcasting connection,
in `resources/js/services/broadcasting.ts`.

A test scans every theme `.vue` file for `DB::`, `Hash::`, `Gate::`, `fetch(`
and similar, and fails if a theme is doing its own data access.

---

## 9. Assets

Keep them in the theme:

```
resources/themes/my-theme/assets/
├── images/
├── icons/
└── fonts/
```

Two ways to use them, for two different needs:

**Bundled** — referenced from the theme's CSS or a `.vue` file. Vite hashes and
emits them, so they are cache-safe and need no publishing step:

```css
background-image: url('../assets/images/parchment.png');
```

**Published** — when a URL must be stable and configurable, for example a logo
an operator points `GAME_LOGO` at:

```bash
php artisan theme:publish          # copies assets/ to public/themes/<slug>/
```

```env
GAME_LOGO=/themes/my-theme/assets/images/logo.png
```

The fantasy theme ships **no binary assets at all**: its mark is generated SVG
drawn from `GAME_SHORT_NAME`, and its textures are CSS gradients. That is
deliberate — nothing to licence, and it adapts to whatever the server is
called.

Web fonts are the theme's own business. Fantasy loads Cinzel (SIL Open Font
License) from its `theme.css`.

### The animated cursor

The panel replaces the ordinary pointer with the game's: an arrow that turns
everywhere, and a glove over anything that can be clicked. Core looks for them
at fixed paths:

```
public/images/cursors/arrow-00.png … arrow-05.png   the arrow, spun in order
public/images/cursors/hand.png                      hovering something clickable
public/images/cursors/hand-press.png                the same, held down
public/images/cursors/arrow-still.png               available, unused by core
```

Each may be joined by an `@2x.png` twin — `arrow-00@2x.png`, `hand@2x.png` — at
double the size. Those are optional, and only used on a high-density screen.

This is core rather than a theme, because a cursor belongs to the window and
not to a page. It is also a drop-in slot like the rest of the artwork: with no
files there, `useAnimatedCursor()` still runs and every image resolves to
nothing, so the browser takes the `auto` and `pointer` fallbacks and the
pointer is simply the ordinary one. Nothing to configure and nothing to break.

Three rules the art has to follow:

- **Pad every image to the same canvas, anchored at its hotspot.** The arrow's
  is fixed at `0 0`, so a frame whose tip sits elsewhere makes the pointer
  wander as the arrow turns. Cropped sprite frames need padding back out:

  ```bash
  magick frame_000.png -background none -gravity NorthWest -extent 32x32 \
         public/images/cursors/arrow-00.png
  ```

  `arrow-still.png` is declared at `1 1` and the glove's hotspot is its
  fingertip, `2 1` for `hand.png` and `2 2` for `hand-press.png`. The pixel of
  difference is not a mistake: the finger curls as it presses, and moving the
  hotspot with it is what keeps the tip pinned to one point on screen while the
  hand recoils underneath. Art with the finger somewhere else needs those two
  numbers changed to match.

- **Stay at or under 32×32.** Browsers ignore a cursor image beyond about
  128px, and several platforms quietly refuse anything over 32.

- **Scale the `@2x` pair with nearest-neighbour.** Cursor images are drawn at
  their intrinsic size in CSS pixels, so a 32px file on a high-density screen
  is upscaled by the compositor and pixel art turns to mush. Smooth filtering
  defeats the point:

  ```bash
  magick hand.png -filter point -resize 200% hand@2x.png
  ```

  The high-density pair is offered through `image-set()`, which is asked for
  with `CSS.supports()` first — Safari only dropped the prefix in 17, and a
  `cursor` the browser cannot parse is discarded rather than fallen back on.

The arrow spends 160ms on each frame but rests on `arrow-00.png` for 200ms, so
it comes to a stop facing forward before going round again rather than tumbling
continuously — a full turn is a round second. Both numbers are constants at the
top of `useAnimatedCursor.ts`.

The cursor turns itself off for a coarse or hover-less pointer, holds that same
resting frame under `prefers-reduced-motion`, and stops spinning while the tab
is hidden.

The spinning arrow is the *inherited* default, which is why it needs no selector
of its own and why the three stills can take it back simply by saying so. The
glove is listed by element instead — `a[href]`, `button`, `summary`, the form
controls that take a click, and the ARIA roles that stand in for them — because
CSS has no way to ask for "whatever computes to `cursor: pointer`". A theme
that builds a clickable thing out of a plain `div` should give it the matching
role, which is worth doing for screen readers anyway. Anything `:disabled` or
`aria-disabled` is left out: offering a hand to a button that will refuse the
click is worse than offering no affordance at all.

Two more rules say the spin out loud where it would otherwise be lost. Text
fields keep the browser's I-beam unless an author rule takes it back, and
disabled controls keep `not-allowed`, which the panel sets both in
`@layer components` and through Tailwind's utility. Both now spin like
everything else. Each keeps its own fallback keyword — `text` and
`not-allowed` — for the case where the image is missing, since the fallback is
the last thing holding the affordance up.

The disabled case is the one place this costs something. `not-allowed` is a
recognised signal that a control will refuse the click and a spinning arrow is
not, so the panel leans on the other half of that convention instead — the
dimming `AppButton` already applies alongside it. If you would rather keep the
native cursor there, drop the last rule in `app.css`; if you would rather be
more emphatic than either, `cursor.spr` carries a red "forbidden" arrow as its
own five-frame spin.

`arrow-still.png` is the arrow at rest. **Core applies it nowhere** — nothing in
the panel stops spinning — but `--cursor-arrow-still` is published for a theme
that has made something genuinely inert and wants the turning to stop over it:

```css
:root[data-theme-slug='my-theme'] .my-inert-link {
    cursor: var(--cursor-arrow-still) 1 1, default;
}
```

It is left out of the preload for the same reason, so a theme that uses it pays
that one fetch itself.

All of these rules sit **outside every `@layer`**, which is worth knowing before
you try to override them. Theme stylesheets and SFC `<style>` blocks are
unlayered, and an unlayered declaration beats a layered one whatever its
specificity — a rule in `base` or `components` would quietly lose to a
`cursor: pointer` on a themed navigation button. So a theme that wants none of
this overrides the same declarations under its own scope, which is specific
enough to win and is equally unlayered:

```css
:root[data-theme-slug='my-theme'].cursor-animated {
    cursor: auto;
}

:root[data-theme-slug='my-theme'].cursor-animated
    :is(a[href], button, summary, select, [role='button']) {
    cursor: pointer;
}
```

**This is artwork.** The pointers a Ragnarok server wants are extracted from the
client's `cursor.spr`, and that art is Gravity's. It is why the path is
a slot rather than something core ships, on the same footing as the hero art
above: whoever drops files in is the one who has to hold the rights to them.
See the licensing note in
[`resources/themes/README.md`](../resources/themes/README.md).

---

## 10. Validation

A misconfigured theme is reported, never silently ignored.

```env
APP_THEME=does-not-exist
APP_THEME_FALLBACK=fantasy      # optional
APP_THEME_STRICT=false          # optional
```

| Situation | Behaviour |
| --- | --- |
| Theme found | Used. |
| Missing, fallback resolves, `STRICT=false` | Fallback used, **warning logged**, `theme:list` reports it. |
| Missing, no fallback | `ThemeNotFound`, naming the installed themes. |
| Missing, `STRICT=true` | `ThemeNotFound` even with a fallback. |
| A theme has broken `theme.json` | That theme is skipped and logged; the rest still work. |

Set `APP_THEME_STRICT=true` in CI so a deployment fails rather than quietly
shipping the fallback.

```bash
php artisan theme:list --strict    # non-zero exit if the fallback is in use
```

---

## 11. Development

```bash
npm run dev
```

Vite watches `resources/themes/**`, so CSS and component edits hot-reload. No
Laravel rebuild for a visual change.

After a build, check that what is on disk is what the build exposes:

```bash
npm run build
npm run verify:themes
```

That catches the two failures that are otherwise silent: a stylesheet that is
not an entrypoint (the skin does not apply), and an override that is not in the
resolver's glob (it is ignored in favour of the core file, with no error).

---

## 12. Creating a theme

```bash
cp -r resources/themes/slate resources/themes/my-theme
```

1. Edit `theme.json` — `name`, `version`, `description`, and
   `default_appearance` if the theme is art-directed for one.
2. Rename the scope in every CSS file: `:root[data-theme-slug='my-theme']`.
3. Replace the palette.
4. Override **blocks** where a section needs to look or behave differently.
   Copy one out of `resources/js/blocks/` into `my-theme/blocks/` and edit it;
   keep the props and the data composable it already uses.
5. Declare **page compositions** in `theme.json` if you want different sections
   or a different order. Omit them to keep the application's pages.
6. Override layouts or whole pages **only where neither of those is enough**.
7. `APP_THEME=my-theme`
8. `npm run build` — needed once, because the directory is new.
9. `npm run verify:themes` — catches a block that will be silently dropped.

`slate` is the better starting point for a recolour; `fantasy` for something
structurally different.

See [`resources/themes/README.md`](../resources/themes/README.md) for the
checklist and the licence notes on third-party assets.

---

## 13. Reference

| | |
| --- | --- |
| `config/theme.php` | Active theme, fallback, directory, strict mode |
| `config/game.php` | Branding: names, logo, favicon, links |
| `app/Services/Theme/ThemeService.php` | The only thing that decides which theme is active |
| `app/Services/Theme/Theme.php` | A theme's manifest, as a value object |
| `app/Services/Theme/ClientBootstrap.php` | The payload sent to the browser |
| `app/Providers/ThemeServiceProvider.php` | Shares the theme with the shell view |
| `resources/js/theme/resolve.ts` | Page, layout and component resolution |
| `resources/js/theme/blocks.ts` | Block registry and page compositions |
| `resources/js/theme/bootstrap.ts` | Reads the payload |
| `resources/js/blocks/contracts.ts` | The block data contracts |
| `resources/js/blocks/data.ts` | The block data layer |
| `resources/js/blocks/*.vue` | The eleven core blocks |
| `resources/js/pages/ComposedPage.vue` | Renders a composition |
| `resources/js/composables/useGame.ts` | Branding for components |
| `resources/js/composables/useShell.ts` | Shell behaviour, so themes need none |
| `resources/js/stores/site.ts` | Statistics, classes and news |
| `resources/views/app.blade.php` | Links the active theme's CSS |
| `vite.config.ts` | Turns each theme's stylesheet into an entrypoint |

Related decisions: [MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md) D7 (why
FluxCP's theme system was replaced rather than ported) and D14 (realtime).
