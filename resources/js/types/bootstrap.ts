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
}

export interface BroadcastingConfig {
    driver: 'reverb'
    /** Public client identifier. The secret never reaches the browser. */
    key: string
    host: string
    port: number
    scheme: 'http' | 'https'
}

export interface PanelBootstrap {
    game: GameConfig
    theme: ThemeConfig
    /** Null when broadcasting is not configured, so the client polls instead. */
    broadcasting: BroadcastingConfig | null
}
