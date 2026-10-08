# Themes

Each directory here is a skin. Switch between them with `APP_THEME` in `.env`.

[`docs/THEMING.md`](../../docs/THEMING.md) is the full reference. This file is
the practical checklist and the licence notes.

```
resources/themes/
├── fantasy/     the default — dark, warm, antique gold; 8 blocks, composed home page
├── skyward/     bright and light-first; a masthead and ticker that float over the hero
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
   data composable. Fourteen core blocks exist; override only the ones you want.

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

### skyward

| Asset | Source | Licence |
| --- | --- | --- |
| Nunito (display) | Google Fonts | SIL Open Font License 1.1 |

Everything else in the theme itself is original: the brand mark is generated
SVG drawn from `GAME_SHORT_NAME`, and the sky, cloud banks and card elevation
are CSS gradients and shadows. The theme directory holds no binary assets.

**It ships no game artwork, and must not.** The hero is a full viewport of key
art, and the page closes on a band of it; both are supplied per installation.
Every slot requests its file and falls back to the generated sky when it 404s,
so there is no placeholder to remove and no code change to make — supplying the
art is dropping files into `public/`:

```
public/videos/hero/skyward-hero.mp4      the hero's background loop, behind the
                                         floating masthead and ticker
public/images/hero/skyward-sky.webp      its poster frame, shown while the
                                         video loads; optional
public/images/world/skyward-horizon.webp the closing call to action, below the
                                         last section and above the footer
```

The closing band is wide and short — roughly 3:1 at full width — and the type
sits in the middle of it, so art whose subject is centred will be covered.
Something that reads at the edges works best.

The video is muted, looped and `playsinline`, which is what lets a browser
autoplay it. Under `prefers-reduced-motion` it is not requested at all and the
generated sky stands in: a paused `<video>` still has to download before it can
show a frame, and a hero loop is a large file to pull down for a still.

Keep that loop small. It is served to every first-time visitor, and a few
seconds of 1080p re-encodes comfortably under 4 MB:

```
ffmpeg -i source.mp4 -an -vf scale=1920:-2 -c:v libx264 -crf 30 -preset slow \
       -movflags +faststart public/videos/hero/skyward-hero.mp4
```

`-an` drops the audio track, which a muted background never plays, and
`+faststart` puts the index at the front of the file so playback can begin
before the whole thing has arrived.

Video is tracked with Git LFS — see `.gitattributes` — so the repository keeps
a pointer rather than the blob, and a clone does not pay for media it may not
want. A deploy target therefore needs `git lfs` installed, or the file copied
across by other means.

### The loading gate

`components/LoadingScreen.vue` holds the public shell back until the hero's
media has arrived, showing a percentage and a running byte count so a visitor
on a slow line can tell the difference between slow and broken. The bytes are
real: `usePreload` in core reads each response as a stream and sums the
`Content-Length` headers.

The mechanism is in core on purpose. A theme may not contain a network call —
a test fails if one does — so the theme declares the manifest and draws the
screen, and nothing else.

It fails open at every step. A missing file, a server that sends no
`Content-Length`, or a connection too slow to finish inside the deadline all
resolve to *show the page anyway*, because every block falls back to its own
source and a gate that can strand somebody is worse than no gate. The deadline
defaults to 20 seconds, set where the manifest is declared.

The completed download is handed to the hero as an object URL, so the video
plays from memory rather than being requested a second time. That is also why
the manifest should stay short: whatever is in it is held in memory for the
life of the page.

Whoever supplies the art is responsible for holding the rights to it.

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
