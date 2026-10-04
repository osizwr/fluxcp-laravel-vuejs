# Themes

Each directory here is a skin. Switch between them with `APP_THEME` in `.env`.

[`docs/THEMING.md`](../../docs/THEMING.md) is the full reference. This file is
the practical checklist and the licence notes.

```
resources/themes/
├── fantasy/     the default — dark, warm, antique gold; 8 blocks, composed home page
├── slate/       a cool, light-first skin; palette only, core pages throughout
├── yatagarasu/  dark fantasy in gold and blood; 13 blocks, composed home page
└── README.md
```

A theme has three layers, and you only use as many as you need:

| Layer | Changes | Costs |
| --- | --- | --- |
| Design tokens | Colours, type, geometry everywhere | One stylesheet |
| Blocks | How a page section looks | One component per section |
| Page composition | Which sections a page has, and their order | A list in `theme.json` |

---

## The one rule

**A theme is presentation.** It may restyle and restructure the interface. It
may not contain a database query, an API call, an authentication step, or a
decision about what someone is allowed to do.

That is not a style preference, it is what keeps themes swappable. A theme that
fetched its own data would silently stop doing so the moment someone installed
a different one. There are tests that fail if a theme file contains `DB::`,
`Hash::`, `Gate::`, `fetch(` or the game's name.

Themes **consume** what the application provides:

```ts
import { useGame } from '@/composables/useGame'      // branding
import { useShell } from '@/composables/useShell'    // nav, sign-out, active route
import { useServerStore } from '@/stores/server'     // live status, already fed by Reverb
import { useAuthStore } from '@/stores/auth'         // the signed-in account
```

Blocks take their data from `@/blocks/data.ts`, each function returning a
contract from `@/blocks/contracts.ts`:

```ts
import { useServerStatusData, useRankingData } from '@/blocks/data'
```

**A block never calls the API.** That is what keeps a theme swappable, and a
test enforces it.

---

## Creating a theme

```bash
cp -r resources/themes/slate resources/themes/my-theme
```

1. **Edit `theme.json`.** `name` and `version` are required. Set
   `default_appearance` to `light` or `dark` if the theme is art-directed for
   one, or omit it to follow the visitor's system.

   Do **not** add a `slug` — the directory name is the slug, and a manifest
   claiming otherwise is rejected.

2. **Rename the CSS scope** in every stylesheet:

   ```css
   :root[data-theme-slug='my-theme'] { … }
   ```

   Always scope to your slug. It wins on specificity regardless of stylesheet
   order, and it stops your rules leaking into another theme.

3. **Replace the palette.** The token list is in `docs/THEMING.md` §4. Because
   core components read these semantic names, this alone restyles the whole
   panel — `slate` does nothing else.

4. **Replace assets** in `assets/`, if you have any. Referenced from CSS or a
   `.vue` file they are bundled and hashed automatically. Only run
   `php artisan theme:publish` if something outside the bundle needs a stable
   URL, such as a logo an operator points `GAME_LOGO` at.

5. **Override blocks** where a section needs different structure. Copy one out
   of `resources/js/blocks/` into `blocks/` and edit it, keeping its props and
   data composable. Eleven core blocks exist; override only the ones you want.

6. **Compose pages** in `theme.json` if you want different sections or a
   different order:

   ```json
   "pages": {
       "home": {
           "layout": "public",
           "blocks": ["hero", { "block": "ranking-showcase", "props": { "limit": 10 } }]
       }
   }
   ```

   Omit this and the application's own pages are used.

7. **Override layouts or whole pages** only where neither of those is enough.
   Drop a file with a matching name into `layouts/` or `pages/`; it replaces
   the core one with no registration step.

8. **Point `.env` at it.**

   ```env
   APP_THEME=my-theme
   ```

9. **Build once.** A new directory has to be seen by Vite:

   ```bash
   npm run build
   npm run verify:themes
   ```

   After that, switching between installed themes needs only `.env`.

---

## Checklist before shipping one

- [ ] `theme.json` has `name` and `version`, and no `slug`
- [ ] Every CSS rule is scoped to `:root[data-theme-slug='<your-slug>']`
- [ ] Both appearances are legible, or `supports.light_mode` / `dark_mode` says otherwise
- [ ] `npm run verify:themes` passes — no stylesheet missing from the build, no override or composed block being silently ignored
- [ ] Every block your compositions name resolves, in your theme or in core
- [ ] Keyboard focus is visible on every interactive element
- [ ] Text meets contrast against its own surfaces, not the core's
- [ ] Any animation you add is disabled under `prefers-reduced-motion`
- [ ] Usable at phone width: no horizontal page scroll, tables scroll rather than overflow
- [ ] No business logic, no data fetching, no hardcoded game name
- [ ] Third-party fonts, icons and images are licensed and recorded below

---

## Third-party assets

### fantasy

| Asset | Source | Licence |
| --- | --- | --- |
| Cinzel (display font) | Google Fonts | SIL Open Font License 1.1 |

Everything else is original: the brand mark is generated SVG drawn from
`GAME_SHORT_NAME`, and the textures and ornaments are CSS gradients and
pseudo-elements. There are no binary assets, which means nothing to licence and
a mark that adapts to whatever the server is called.

### slate

No third-party assets.

### yatagarasu

| Asset | Source | Licence |
| --- | --- | --- |
| Cinzel (display) | Google Fonts | SIL Open Font License 1.1 |
| Cinzel Decorative (brand, large titles) | Google Fonts | SIL Open Font License 1.1 |
| Crimson Text (prose) | Google Fonts | SIL Open Font License 1.1 |
| Share Tech Mono (figures) | Google Fonts | SIL Open Font License 1.1 |

Everything else is original. The three-legged crow and the ornament star are
generated SVG that inherit `currentColor`, and the washes, rules and textures
are CSS gradients, so the theme ships no binary assets.

**It ships no game artwork, and must not.** Every image in the design is a
named slot that falls back to a labelled placeholder, listed below. Supplying
them is dropping files into `public/images/` — the slot requests its path and
uses it the moment it exists, with no code change. Whoever supplies the art is
responsible for holding the rights to it.

```
public/images/hero/yatagarasu-world.webp    the hero's key art
public/images/hero/flourish-left.webp       character art flanking the emblem;
public/images/hero/flourish-right.webp      purely decorative, so these two
                                            render nothing at all when absent
public/images/world/yatagarasu-gate.webp    the banner under the hero
public/images/world/yatagarasu-lore.webp    the legend section
public/images/world/yatagarasu-dawn.webp    the closing call to action
public/images/classes/<job-name>.webp       one per class, named after the job
                                            the server reports, lower-cased and
                                            hyphenated: `High Priest` resolves
                                            to `high-priest.webp`
```

---

## Licensing your own theme

No game artwork from Ragnarok Online or any other game may be shipped here
unless this project holds the rights to it — and it does not. Use original work
or properly licensed assets, and record them in the table above.

Note that this project is LGPL-3.0 (see [`NOTICE`](../../NOTICE)). A theme
distributed with it is covered by the same terms; one you keep to yourself is
your own business.

The `author` field in both shipped themes is empty on purpose: this project's
copyright holder has not been set. Put your own name in yours.
