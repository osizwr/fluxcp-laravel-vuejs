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
    /*
     * English, because a payload that failed to arrive is not evidence about
     * what language the visitor reads -- and the strings compiled into the
     * bundle are English.
     */
    locale: { active: 'en', available: ['en'] },
    game: {
        name: 'Control Panel',
        shortName: 'CP',
        description: '',
        version: null,
        logo: null,
        links: {},
        // Empty, so the shell falls back to its own built-in navigation.
        nav: [],
        // No groups, so the footer renders its brand and small print only.
        footer: { groups: [], socials: [] },
        /*
         * No small print. The disclaimer and the credit are the operator's
         * words, and a payload that failed to arrive is the last place to
         * invent a legal notice on their behalf.
         */
        legal: { disclaimer: null, copyright: null, credit: null },
    },
    theme: {
        slug: 'default',
        name: 'Default',
        version: '0.0.0',
        supports: {},
        defaultAppearance: null,
        layouts: {},
        pages: {},
    },
    broadcasting: null,
    announcement: null,
    features: [],
    /*
     * No downloads. The page renders its own "nothing published yet" state
     * rather than inventing a mirror, which would hand somebody a link that
     * cannot work.
     */
    downloads: { notice: null, clients: [], requirements: [], steps: [] },
    /*
     * Everything off. A payload that failed to arrive must not leave the
     * account forms guessing: offering registration that the server then
     * refuses is worse than not offering it, and a password hint invented here
     * would describe a policy nobody configured.
     */
    accounts: {
        registrationEnabled: false,
        passwordResetEnabled: false,
        emailChangeRequiresConfirmation: true,
        registrationRequiresConfirmation: false,
        confirmationCodeLength: 6,
        username: { minLength: 4, maxLength: 23 },
        password: {
            minLength: 8,
            maxLength: 31,
            minUppercase: 0,
            minLowercase: 0,
            minNumbers: 0,
            minSymbols: 0,
            allowUsernameInside: false,
        },
        captcha: {
            onRegistration: false,
            onLogin: false,
            selfHosted: true,
            siteKey: null,
        },
    },
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
            locale: { ...FALLBACK.locale, ...(parsed.locale ?? {}) },
            game: { ...FALLBACK.game, ...(parsed.game ?? {}) },
            theme: { ...FALLBACK.theme, ...(parsed.theme ?? {}) },
            broadcasting: parsed.broadcasting ?? null,
            announcement: parsed.announcement ?? null,
            features: parsed.features ?? [],
            downloads: { ...FALLBACK.downloads, ...(parsed.downloads ?? {}) },
            accounts: { ...FALLBACK.accounts, ...(parsed.accounts ?? {}) },
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
