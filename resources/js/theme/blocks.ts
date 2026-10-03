import { defineAsyncComponent, type Component } from 'vue'
import { activeThemeSlug, bootstrap } from './bootstrap'
import type { PageComposition } from '../types/bootstrap'

/**
 * The block registry, and the page compositions that use it.
 *
 * A block is a named section of a page. The page composition says which blocks
 * appear and in what order; the registry says which component a name resolves
 * to. Keeping those apart is the point: reordering a page is editing a list in
 * theme.json, not editing a component.
 *
 *     theme.json  ->  composition  ->  block name  ->  registry  ->  component
 *
 * Resolution order for each name, as the brief specifies:
 *
 *     active theme's blocks/<Name>.vue   ->   the theme's own
 *     resources/js/blocks/<Name>.vue     ->   the core default
 *     neither                            ->   skipped, and reported at build time
 *
 * That fallback is what means a new theme does not have to reimplement
 * eleven blocks to change three of them.
 */

type ModuleLoader = () => Promise<{ default: Component }>

/* Static literals, so Vite can analyse them. */
const coreBlocks = import.meta.glob('../blocks/*.vue') as Record<string, ModuleLoader>
const themeBlocks = import.meta.glob('../../themes/*/blocks/*.vue') as Record<string, ModuleLoader>

/**
 * `server-status` -> `ServerStatus`.
 *
 * Compositions name blocks in kebab-case because they are configuration;
 * files are PascalCase because they are Vue components. One translation, here.
 */
export function blockFileName(blockName: string): string {
    return blockName
        .split(/[-_]/)
        .filter((part) => part !== '')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join('')
}

function findBySuffix(map: Record<string, ModuleLoader>, suffix: string): ModuleLoader | null {
    const key = Object.keys(map).find((candidate) => candidate.endsWith(suffix))

    return key === undefined ? null : map[key]
}

/**
 * The component for a block name, or null if nothing provides it.
 *
 * Null is returned rather than thrown: one misnamed block in a composition
 * should not blank the whole page. `npm run verify:themes` fails on it instead,
 * which is the right place to catch a configuration mistake.
 */
export function resolveBlock(blockName: string): Component | null {
    const file = blockFileName(blockName)

    const loader =
        findBySuffix(themeBlocks, `/themes/${activeThemeSlug()}/blocks/${file}.vue`) ??
        findBySuffix(coreBlocks, `/blocks/${file}.vue`)

    return loader === null ? null : defineAsyncComponent(loader)
}

export function blockExists(blockName: string): boolean {
    const file = blockFileName(blockName)

    return (
        findBySuffix(themeBlocks, `/themes/${activeThemeSlug()}/blocks/${file}.vue`) !== null ||
        findBySuffix(coreBlocks, `/blocks/${file}.vue`) !== null
    )
}

/**
 * Whether a block comes from the theme or from core. For documentation and
 * the verification script; not used for control flow.
 */
export function blockSource(blockName: string): 'theme' | 'core' | null {
    const file = blockFileName(blockName)

    if (findBySuffix(themeBlocks, `/themes/${activeThemeSlug()}/blocks/${file}.vue`) !== null) {
        return 'theme'
    }

    return findBySuffix(coreBlocks, `/blocks/${file}.vue`) !== null ? 'core' : null
}

/* -------------------------------------------------------------------------- */
/* Page composition                                                           */
/* -------------------------------------------------------------------------- */

/**
 * The composition the active theme declares for a page, if any.
 *
 * Page keys are route names, so a theme composes `home` rather than having to
 * know a file path.
 */
export function compositionFor(pageKey: string): PageComposition | null {
    return bootstrap().theme.pages[pageKey] ?? null
}

export function hasComposition(pageKey: string): boolean {
    return compositionFor(pageKey) !== null
}

/**
 * The blocks of a composition, with anything unresolvable dropped.
 *
 * @return Each entry pairs the resolved component with the props the
 *         composition passed, so the renderer stays a loop over this list.
 */
export function composedBlocks(
    pageKey: string,
): Array<{ name: string; component: Component; props: Record<string, unknown> }> {
    const composition = compositionFor(pageKey)

    if (composition === null) {
        return []
    }

    return composition.blocks
        .map((entry) => {
            const component = resolveBlock(entry.block)

            return component === null
                ? null
                : { name: entry.block, component, props: entry.props ?? {} }
        })
        .filter(
            (entry): entry is { name: string; component: Component; props: Record<string, unknown> } =>
                entry !== null,
        )
}
