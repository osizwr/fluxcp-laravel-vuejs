import type {
    ClassDistributionEntry,
    NewsArticle,
    RankingEntry,
    ServerGroupStatus,
    ServerStatistics,
} from '../types/api'
import type { DownloadClient, InstallStep, RequirementGroup } from '../types/bootstrap'

/**
 * The data contracts every block is built against.
 *
 * These exist so a theme cannot invent its own backend shape. A block in any
 * theme receives the same structures, which is what makes a theme swappable:
 * if each theme defined its own contract, switching one would mean changing
 * the API, and the backend would no longer be independent of the presentation.
 *
 * A block therefore has two kinds of input, and they are deliberately
 * separate:
 *
 *   props     presentation choices the *page composition* makes -- a heading,
 *             how many rows to show, whether to link onward. These come from
 *             theme.json and are the theme's business.
 *
 *   data      application state, obtained from the composables in ./data.ts.
 *             Never fetched by the block itself.
 *
 * Adding a field here is a change to every theme's expectations, so it is the
 * one place in the theme system that should move slowly.
 */

/* -------------------------------------------------------------------------- */
/* Shared                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * A call to action. `to` is an internal route; `href` an external URL. Exactly
 * one is set, so a block never has to guess which kind of link to render.
 */
export interface BlockAction {
    label: string
    to?: string
    href?: string
}

/** The three states a block's data can be in, so every block handles them alike. */
export interface BlockState {
    loading: boolean
    error: string | null
    empty: boolean
}

/* -------------------------------------------------------------------------- */
/* Announcement                                                               */
/* -------------------------------------------------------------------------- */

export interface AnnouncementData {
    /** Derived from the message, so editing it un-dismisses the bar. */
    id: string
    message: string
    url: string | null
    label: string | null
    tone: 'info' | 'event' | 'maintenance'
    dismissible: boolean
}

/* -------------------------------------------------------------------------- */
/* Hero                                                                       */
/* -------------------------------------------------------------------------- */

export interface HeroData {
    title: string
    subtitle: string
    description: string
    primaryAction: BlockAction | null
    secondaryAction: BlockAction | null
    /** Null until server status has loaded, so the hero can omit the line. */
    playersOnline: number | null
    serversUp: boolean
}

export interface HeroProps {
    /** Overrides the game name, for a page hero that is not the front page. */
    title?: string
    subtitle?: string
    description?: string
    /** Whether to show the live player count. */
    showStatus?: boolean
}

/* -------------------------------------------------------------------------- */
/* Server status                                                              */
/* -------------------------------------------------------------------------- */

/** One emulator process. */
export interface ProcessStatus {
    label: string
    up: boolean
}

export interface ServerStatusData {
    groups: ServerGroupStatus[]
    /** The three processes of the first world, for a compact summary. */
    processes: ProcessStatus[]
    playersOnline: number
    playersPeak: number | null
    anyServerUp: boolean
    woeActive: boolean
    measuredAt: string | null
    state: BlockState
}

export interface ServerStatusProps {
    heading?: string
    /** Show every configured world, rather than a single summary. */
    detailed?: boolean
}

/* -------------------------------------------------------------------------- */
/* Statistics                                                                 */
/* -------------------------------------------------------------------------- */

/** A single headline figure, already formatted for display. */
export interface StatisticItem {
    key: string
    label: string
    value: number
    formatted: string
}

export interface StatisticsData {
    items: StatisticItem[]
    raw: ServerStatistics | null
    state: BlockState
}

export interface StatisticsProps {
    heading?: string
}

/* -------------------------------------------------------------------------- */
/* Class showcase                                                             */
/* -------------------------------------------------------------------------- */

export interface ClassShowcaseData {
    classes: ClassDistributionEntry[]
    /** The largest count, so a block can draw proportional bars. */
    busiest: number
    state: BlockState
}

export interface ClassShowcaseProps {
    heading?: string
    limit?: number
}

/* -------------------------------------------------------------------------- */
/* Rankings                                                                   */
/* -------------------------------------------------------------------------- */

export type RankingLadder = 'level' | 'zeny'

export interface RankingData {
    entries: RankingEntry[]
    ladder: RankingLadder
    state: BlockState
}

export interface RankingProps {
    heading?: string
    ladder?: RankingLadder
    limit?: number
    /** Whether to render a link through to the full ladder. */
    showAll?: boolean
}

/* -------------------------------------------------------------------------- */
/* News                                                                       */
/* -------------------------------------------------------------------------- */

export interface NewsData {
    articles: NewsArticle[]
    state: BlockState
}

export interface NewsProps {
    heading?: string
    limit?: number
    /**
     * Give the first article more prominence. The legacy schema has no
     * "featured" flag, so this means "the newest one", not an editorial
     * choice someone made.
     */
    featureFirst?: boolean
}

/* -------------------------------------------------------------------------- */
/* Features                                                                   */
/* -------------------------------------------------------------------------- */

export interface FeatureItem {
    title: string
    description: string
    url: string | null
}

export interface FeatureData {
    items: FeatureItem[]
    state: BlockState
}

export interface FeatureProps {
    heading?: string
}

/* -------------------------------------------------------------------------- */
/* Downloads                                                                  */
/* -------------------------------------------------------------------------- */

/**
 * Everything the three download blocks read.
 *
 * One contract rather than three, because all of it comes from the same
 * operator-authored configuration and splitting it would mean three
 * composables parsing the same payload. Each block takes the list it draws and
 * ignores the rest.
 *
 * The shapes are the bootstrap's own rather than copies of them: this data
 * never leaves the payload it arrived in, so a second declaration would only
 * be somewhere for the two to drift apart.
 *
 * `state.empty` means the whole page has nothing -- no packages, no
 * specifications, no steps -- which is the only case the page itself has to
 * handle. A section with nothing in it simply does not render.
 */
export interface DownloadsData {
    notice: string | null
    clients: DownloadClient[]
    requirements: RequirementGroup[]
    steps: InstallStep[]
    state: BlockState
}

export interface DownloadsProps {
    heading?: string
    description?: string
    /**
     * Whether the block draws its own container and card.
     *
     * True by default, which is what a block dropped onto a composed page
     * needs: its own width, its own padding and its own panel, owing nothing
     * to whatever is above it.
     *
     * A page that is already one card sets it false and the block renders
     * bare into it. That is the difference between a page that is one white
     * card and a page that is a stack of them, and it has to be the caller's
     * decision -- the block cannot see what it was placed inside.
     */
    framed?: boolean
}

/* -------------------------------------------------------------------------- */
/* Call to action                                                             */
/* -------------------------------------------------------------------------- */

export interface CallToActionData {
    title: string
    description: string
    action: BlockAction | null
    secondaryAction: BlockAction | null
}

export interface CallToActionProps {
    heading?: string
    description?: string
}
