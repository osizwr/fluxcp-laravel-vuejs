import type { PanelBootstrap } from '../types/bootstrap'

/**
 * Reads the configuration the server embedded in the page.
 *
 * It arrives as a JSON script block rather than compiled into the bundle, so
 * one build serves every environment and APP_THEME or GAME_NAME take effect on
 * the next request instead of the next deploy.
 *
 * Parsed once. If the block is missing or malformed the application still
 * renders with neutral defaults, because a panel that shows nothing at all is
 * a worse failure than one showing the wrong name.
 */

const FALLBACK: PanelBootstrap = {
    game: {
        name: 'Control Panel',
        shortName: 'CP',
        description: '',
        version: null,
        logo: null,
        links: {},
    },
    theme: {
        slug: 'default',
        name: 'Default',
        version: '0.0.0',
        supports: {},
        defaultAppearance: null,
    },
    broadcasting: null,
}

let cached: PanelBootstrap | null = null

export function bootstrap(): PanelBootstrap {
    if (cached !== null) {
        return cached
    }

    const element = document.getElementById('panel-bootstrap')

    if (element === null || element.textContent === null) {
        return (cached = FALLBACK)
    }

    try {
        const parsed = JSON.parse(element.textContent) as Partial<PanelBootstrap>

        cached = {
            game: { ...FALLBACK.game, ...(parsed.game ?? {}) },
            theme: { ...FALLBACK.theme, ...(parsed.theme ?? {}) },
            broadcasting: parsed.broadcasting ?? null,
        }
    } catch {
        cached = FALLBACK
    }

    return cached
}

/**
 * The active theme's slug.
 *
 * Read from the payload rather than from the document attribute so there is
 * one source of truth, and so a test can substitute a payload.
 */
export function activeThemeSlug(): string {
    return bootstrap().theme.slug
}
