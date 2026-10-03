/**
 * The configuration the server embeds in the page.
 *
 * Mirrors App\Services\Theme\ClientBootstrap. Written by hand so a change on
 * the server surfaces as a type error here rather than as undefined at runtime.
 */

export interface GameConfig {
    name: string
    shortName: string
    description: string
    version: string | null
    logo: string | null
    /** Only links the operator configured; an absent key means no such link. */
    links: Partial<Record<'website' | 'downloads' | 'discord' | 'forum', string>>
}

/** One block in a page composition, as declared in theme.json. */
export interface BlockDefinition {
    /** Kebab-case block name, resolved through the block registry. */
    block: string
    /** Presentation options passed to the block as props. */
    props?: Record<string, unknown>
}

/**
 * How a theme composes one page: which layout wraps it, and which blocks
 * appear in which order.
 */
export interface PageComposition {
    layout: string
    blocks: BlockDefinition[]
}

export interface ThemeConfig {
    slug: string
    name: string
    version: string
    supports: Record<string, boolean>
    /**
     * The appearance the theme is art-directed for, or null to follow the
     * visitor's operating system.
     */
    defaultAppearance: 'light' | 'dark' | null
    /** Named layout components the theme provides, by role. */
    layouts: Record<string, string>
    /** Page compositions, keyed by route name. Empty when the theme declares none. */
    pages: Record<string, PageComposition>
}

export interface BroadcastingConfig {
    driver: 'reverb'
    /** Public client identifier. The secret never reaches the browser. */
    key: string
    host: string
    port: number
    scheme: 'http' | 'https'
}

/** The operator's announcement, or null when there is nothing to announce. */
export interface AnnouncementConfig {
    id: string
    message: string
    url: string | null
    label: string | null
    tone: 'info' | 'event' | 'maintenance'
    dismissible: boolean
}

export interface FeatureConfig {
    title: string
    description: string
    url: string | null
}

export interface PanelBootstrap {
    game: GameConfig
    theme: ThemeConfig
    /** Null when broadcasting is not configured, so the client polls instead. */
    broadcasting: BroadcastingConfig | null
    announcement: AnnouncementConfig | null
    features: FeatureConfig[]
}
