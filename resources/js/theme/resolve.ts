import { defineAsyncComponent, type Component } from 'vue'
import { activeThemeSlug, bootstrap } from './bootstrap'
import { compositionFor, hasComposition } from './blocks'

/**
 * Resolves a component, layout or page to the active theme's version of it,
 * falling back to the application's own.
 *
 *     active theme provides it?  ->  use the theme's
 *     otherwise                  ->  use the core default
 *
 * This is the whole override mechanism. There is no registry to maintain and
 * no condition anywhere on a theme's name: a theme overrides something by
 * putting a file with the matching name in the matching directory.
 *
 *     resources/themes/<slug>/pages/HomePage.vue       overrides
 *     resources/js/pages/HomePage.vue
 *
 * Every theme's files are known to Vite at build time and become their own
 * lazy chunks, so an inactive theme costs a visitor nothing: its chunks are
 * never requested. The trade is that adding a *new* theme directory needs
 * `npm run build`, because Vite has to have seen the files. Switching between
 * themes that were present at build time needs only APP_THEME.
 */

type ModuleLoader = () => Promise<{ default: Component }>

/*
 * The glob patterns must be static literals for Vite to analyse them, which is
 * why the theme slug is applied when looking a key up rather than here.
 */
const themePages = import.meta.glob('../../themes/*/pages/*.vue') as Record<string, ModuleLoader>
const themeLayouts = import.meta.glob('../../themes/*/layouts/*.vue') as Record<
    string,
    ModuleLoader
>
const themeComponents = import.meta.glob('../../themes/*/components/*.vue') as Record<
    string,
    ModuleLoader
>

/**
 * Find a theme's override, if it has one.
 *
 * Matched on a path suffix rather than an exact key. Vite's glob keys are
 * relative to this file, but that is an implementation detail of the bundler
 * and not something worth depending on; a suffix match survives it changing.
 */
function findOverride(
    map: Record<string, ModuleLoader>,
    kind: 'pages' | 'layouts' | 'components',
    name: string,
): ModuleLoader | null {
    const suffix = `/themes/${activeThemeSlug()}/${kind}/${name}.vue`
    const key = Object.keys(map).find((candidate) => candidate.endsWith(suffix))

    return key === undefined ? null : map[key]
}

/**
 * A page for the router: the theme's if it has one, otherwise the core page.
 *
 * Returns a loader rather than a component so the router keeps code-splitting
 * per route.
 */
export function themedPage(name: string, fallback: ModuleLoader): ModuleLoader {
    return findOverride(themePages, 'pages', name) ?? fallback
}

/**
 * A layout component, resolved the same way.
 */
export function themedLayout(name: string, fallback: ModuleLoader): Component {
    return defineAsyncComponent(findOverride(themeLayouts, 'layouts', name) ?? fallback)
}

/**
 * A UI component, resolved the same way.
 *
 * Used where a theme may want to restructure a component rather than restyle
 * it. Most components need no override at all: they read the semantic CSS
 * variables, so a theme that ships only `variables.css` restyles them already.
 */
export function themedComponent(name: string, fallback: ModuleLoader): Component {
    return defineAsyncComponent(findOverride(themeComponents, 'components', name) ?? fallback)
}

/**
 * Whether the active theme overrides a given thing.
 *
 * Exposed for the theme documentation and for tests; not used for control
 * flow, which would reintroduce exactly the branching this design avoids.
 */
export function overridesProvidedBy(slug: string): Record<string, string[]> {
    const collect = (map: Record<string, ModuleLoader>, kind: string): string[] =>
        Object.keys(map)
            .filter((key) => key.includes(`/themes/${slug}/${kind}/`))
            .map((key) => key.replace(/^.*\/([^/]+)\.vue$/, '$1'))
            .sort()

    return {
        pages: collect(themePages, 'pages'),
        layouts: collect(themeLayouts, 'layouts'),
        components: collect(themeComponents, 'components'),
    }
}

/* -------------------------------------------------------------------------- */
/* Routes                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * The component for a route, resolved through the three levels a theme has.
 *
 *   1. the theme's own pages/<Name>.vue   -- full control, a hand-built page
 *   2. a composition in theme.json        -- declarative, a list of blocks
 *   3. the core pages/<Name>.vue          -- the application's default
 *
 * A theme can therefore take over a page entirely, rearrange it from
 * configuration, or leave it alone, and the router does not care which.
 *
 * @param pageKey The route name, which is how a composition addresses a page.
 */
export function themedRoute(pageKey: string, name: string, fallback: ModuleLoader): ModuleLoader {
    const override = findOverride(themePages, 'pages', name)

    if (override !== null) {
        return override
    }

    if (hasComposition(pageKey)) {
        return () => import('../pages/ComposedPage.vue')
    }

    return fallback
}

/* -------------------------------------------------------------------------- */
/* Layouts by role                                                            */
/* -------------------------------------------------------------------------- */

/**
 * Core layouts, by component name.
 *
 * A theme overrides any of these by providing layouts/<Name>.vue; this is the
 * fallback each name resolves to.
 */
const coreLayouts: Record<string, ModuleLoader> = {
    AppLayout: () => import('../layouts/AppLayout.vue'),
    PublicLayout: () => import('../layouts/PublicLayout.vue'),
    AuthLayout: () => import('../layouts/AuthLayout.vue'),
}

/**
 * The default component for each layout role.
 *
 * Roles are the vocabulary a theme uses in theme.json, so that a theme can say
 * "this page is public" without knowing what the core calls that layout.
 */
const roleDefaults: Record<string, string> = {
    public: 'PublicLayout',
    app: 'AppLayout',
    auth: 'AuthLayout',
}

/**
 * Resolve a layout role to a component.
 *
 * The theme's `layouts` map may rename the component for a role; otherwise the
 * core default for that role is used. An unknown role falls back to the
 * application shell rather than rendering nothing.
 */
export function layoutForRole(role: string): Component {
    const themeLayouts = bootstrap().theme.layouts
    const name = themeLayouts[role] ?? roleDefaults[role] ?? 'AppLayout'
    const fallback = coreLayouts[name] ?? coreLayouts.AppLayout

    return themedLayout(name, fallback)
}

/**
 * The layout role a page should use: the composition's choice, then the
 * route's own declaration, then the application shell.
 */
export function layoutRoleFor(pageKey: string, routeDeclared?: string): string {
    return compositionFor(pageKey)?.layout ?? routeDeclared ?? 'app'
}
