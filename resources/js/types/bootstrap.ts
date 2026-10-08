/**
 * The configuration the server embeds in the page.
 *
 * Mirrors App\Services\Theme\ClientBootstrap. Written by hand so a change on
 * the server surfaces as a type error here rather than as undefined at runtime.
 */

/**
 * One configured link, in the masthead or in a footer group.
 *
 * At most one destination. Both null means the section does not exist yet, and
 * it is drawn in place without being made clickable.
 */
export interface ConfiguredLink {
    label: string
    /** An internal route. */
    to: string | null
    /** An external address. */
    href: string | null
}

export interface FooterGroup {
    heading: string
    links: ConfiguredLink[]
}

/** A social button. A null href is a network the operator has not set up. */
export interface FooterSocial {
    network: string
    href: string | null
}

export interface FooterConfig {
    groups: FooterGroup[]
    socials: FooterSocial[]
}

/** An optional "Designed by" credit. The URL only makes the name a link. */
export interface CreditConfig {
    name: string
    url: string | null
}

/**
 * The footer's small print.
 *
 * Server-side configuration rather than theme markup: the affiliation notice
 * names the game, and no frontend file may hardcode that. Null means the line
 * is omitted rather than rendered empty.
 */
export interface LegalConfig {
    /** The affiliation notice, with the game's name already substituted in. */
    disclaimer: string | null
    /** The holder named in the copyright line; defaults to the game's name. */
    copyright: string | null
    credit: CreditConfig | null
}

/** The language the server chose, and the ones it will accept. */
export interface LocaleConfig {
    active: string
    available: string[]
}

export interface GameConfig {
    name: string
    shortName: string
    description: string
    version: string | null
    logo: string | null
    /** Only links the operator configured; an absent key means no such link. */
    links: Partial<
        Record<'website' | 'downloads' | 'discord' | 'forum' | 'donate' | 'facebook', string>
    >
    /** The masthead's links. Empty means "use the panel's built-in navigation". */
    nav: ConfiguredLink[]
    footer: FooterConfig
    legal: LegalConfig
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

/** The platforms the download page draws an icon and a label for. */
export type DownloadPlatform = 'windows' | 'macos' | 'linux' | 'android' | 'ios'

/** One file. Both halves are always present; the server drops the rest. */
export interface DownloadMirror {
    label: string
    url: string
}

/**
 * One package on the download page.
 *
 * Every field but the name and the lists is nullable, and null means the line
 * is omitted rather than rendered with a placeholder. An empty `mirrors` is
 * meaningful: the package is configured but has nowhere to download from yet,
 * and the card says so.
 */
export interface DownloadClient {
    name: string
    /** Null when the operator named a platform this panel has no icon for. */
    platform: DownloadPlatform | null
    /** A short word beside the name — New, Beta, Optional. */
    badge: string | null
    version: string | null
    size: string | null
    updated: string | null
    description: string
    mirrors: DownloadMirror[]
    /** Caveats that belong to this package rather than to the page. */
    notes: string[]
}

export interface RequirementRow {
    label: string
    value: string
}

/** One tab of the specification table. Never empty: the server drops those. */
export interface RequirementGroup {
    heading: string
    rows: RequirementRow[]
}

/** One installation step. Numbered by position, not by a stored number. */
export interface InstallStep {
    title: string
    description: string
}

/**
 * The download page's contents.
 *
 * Entirely operator-authored, like the feature list: the panel cannot know
 * what a given server ships or where it is hosted. Every list may be empty,
 * and an empty one is a section the page omits.
 */
export interface DownloadsConfig {
    /** A line above the packages, or null for no notice at all. */
    notice: string | null
    clients: DownloadClient[]
    requirements: RequirementGroup[]
    steps: InstallStep[]
}

export interface PasswordPolicy {
    minLength: number
    maxLength: number
    minUppercase: number
    minLowercase: number
    minNumbers: number
    minSymbols: number
    allowUsernameInside: boolean
}

export interface CaptchaConfig {
    onRegistration: boolean
    onLogin: boolean
    /** True when the image comes from this application rather than a third party. */
    selfHosted: boolean
    /** reCAPTCHA's public site key, or null when the challenge is self-hosted. */
    siteKey: string | null
}

/**
 * What the account forms need before they render.
 *
 * The password policy is published on purpose, so a form can state the rules
 * up front instead of rejecting a password afterwards. It is enforced on the
 * server regardless -- this copy is for the interface only.
 */
export interface AccountsConfig {
    registrationEnabled: boolean
    passwordResetEnabled: boolean
    emailChangeRequiresConfirmation: boolean
    registrationRequiresConfirmation: boolean
    /** How many digits a confirmation code has, so the form can draw that many boxes. */
    confirmationCodeLength: number
    username: { minLength: number; maxLength: number }
    password: PasswordPolicy
    captcha: CaptchaConfig
}

export interface PanelBootstrap {
    locale: LocaleConfig
    game: GameConfig
    theme: ThemeConfig
    /** Null when broadcasting is not configured, so the client polls instead. */
    broadcasting: BroadcastingConfig | null
    announcement: AnnouncementConfig | null
    features: FeatureConfig[]
    downloads: DownloadsConfig
    accounts: AccountsConfig
}
