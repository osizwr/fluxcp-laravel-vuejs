# Theming

How the panel's appearance is separated from what it does, and how to build a
skin of your own.

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

Two themes ship:

| Slug | What it is |
| --- | --- |
| `fantasy` | The default. Dark, warm, antique gold — an adventurer's guild ledger. |
| `slate` | A cool, light-first skin that ships **nothing but a palette**, as a worked example. |

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
├── theme.json          required — the manifest
├── styles/
│   ├── theme.css       the Vite entrypoint; imports the others
│   ├── variables.css   the palette
│   └── components.css  surfaces and ornament
├── layouts/            override AppLayout, AuthLayout
├── pages/              override any core page
├── components/         override core components, or add theme-only ones
└── assets/             images, icons, fonts the theme owns
```

Only `theme.json` is required. A theme consisting of nothing but `theme.json`
and `styles/theme.css` is valid and useful — see `slate`.

### theme.json

```json
{
    "name": "Fantasy",
    "version": "1.0.0",
    "author": "",
    "description": "A dark, warm skin in the manner of an adventurer's ledger.",
    "supports": { "dark_mode": true, "light_mode": true, "mobile": true },
    "default_appearance": "dark"
}
```

| Field | |
| --- | --- |
| `name`, `version` | **Required.** A theme missing either is ignored and logged. |
| `author` | Free text. Empty in the shipped themes because this project's copyright holder has not been set — see [NOTICE](../NOTICE). |
| `description` | Shown in `theme:list`. |
| `supports` | Advisory flags, readable from the client via `useGame().supports()`. |
| `default_appearance` | `light`, `dark`, or omitted to follow the visitor's OS. |

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

## 6. Data and realtime

Themes **consume** data. They never fetch it.

```ts
const servers = useServerStore()   // server status, kept current for you
const auth = useAuthStore()        // the signed-in account
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

## 7. Assets

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

---

## 8. Validation

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

## 9. Development

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

## 10. Creating a theme

```bash
cp -r resources/themes/slate resources/themes/my-theme
```

1. Edit `theme.json` — `name`, `version`, `description`, and
   `default_appearance` if the theme is art-directed for one.
2. Rename the scope in every CSS file: `:root[data-theme-slug='my-theme']`.
3. Replace the palette.
4. Override layouts, pages or components **only where restyling is not enough**.
5. `APP_THEME=my-theme`
6. `npm run build` — needed once, because the directory is new.
7. `npm run verify:themes`

`slate` is the better starting point for a recolour; `fantasy` for something
structurally different.

See [`resources/themes/README.md`](../resources/themes/README.md) for the
checklist and the licence notes on third-party assets.

---

## 11. Reference

| | |
| --- | --- |
| `config/theme.php` | Active theme, fallback, directory, strict mode |
| `config/game.php` | Branding: names, logo, favicon, links |
| `app/Services/Theme/ThemeService.php` | The only thing that decides which theme is active |
| `app/Services/Theme/Theme.php` | A theme's manifest, as a value object |
| `app/Services/Theme/ClientBootstrap.php` | The payload sent to the browser |
| `app/Providers/ThemeServiceProvider.php` | Shares the theme with the shell view |
| `resources/js/theme/resolve.ts` | Override resolution |
| `resources/js/theme/bootstrap.ts` | Reads the payload |
| `resources/js/composables/useGame.ts` | Branding for components |
| `resources/js/composables/useShell.ts` | Shell behaviour, so themes need none |
| `resources/views/app.blade.php` | Links the active theme's CSS |
| `vite.config.ts` | Turns each theme's stylesheet into an entrypoint |

Related decisions: [MIGRATION_DECISIONS.md](MIGRATION_DECISIONS.md) D7 (why
FluxCP's theme system was replaced rather than ported) and D14 (realtime).
