# Themes

Each directory here is a skin. Switch between them with `APP_THEME` in `.env`.

[`docs/THEMING.md`](../../docs/THEMING.md) is the full reference. This file is
the practical checklist and the licence notes.

```
resources/themes/
├── fantasy/     the default — dark, warm, antique gold
├── slate/       a cool, light-first skin; palette only
└── README.md
```

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

5. **Override only what restyling cannot do.** Drop a file with a matching name
   into `layouts/`, `pages/` or `components/`; it replaces the core one with no
   registration step. If the theme does not provide it, the core file is used.

6. **Point `.env` at it.**

   ```env
   APP_THEME=my-theme
   ```

7. **Build once.** A new directory has to be seen by Vite:

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
- [ ] `npm run verify:themes` passes — no stylesheet missing from the build, no override being silently ignored
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
