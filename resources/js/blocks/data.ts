import { computed, onMounted, type ComputedRef } from 'vue'
import { useGame } from '../composables/useGame'
import { useAccounts } from '../composables/useAccounts'
import { api } from '../services/api'
import { bootstrap } from '../theme/bootstrap'
import { translate as t } from '../i18n'
import { useAuthStore } from '../stores/auth'
import { useServerStore } from '../stores/server'
import { useSiteStore } from '../stores/site'
import { ref } from 'vue'
import type { RankingEntry } from '../types/api'
import type {
    AnnouncementData,
    BlockAction,
    CallToActionData,
    ClassShowcaseData,
    DownloadsData,
    FeatureData,
    HeroData,
    HeroProps,
    NewsData,
    RankingData,
    RankingLadder,
    ServerStatusData,
    StatisticsData,
} from './contracts'

/**
 * The data layer every block reads from.
 *
 * This is the boundary the theme system depends on. Blocks -- including a
 * theme's own -- call these and never touch the API or a store directly, so a
 * theme contains no data access and switching one cannot change what is
 * fetched.
 *
 * Each composable returns one of the contracts in ./contracts.ts.
 */

/* -------------------------------------------------------------------------- */
/* Announcement                                                               */
/* -------------------------------------------------------------------------- */

/**
 * The operator's announcement, or null when there is nothing to announce.
 *
 * Dismissal is remembered per announcement id, which is a hash of the message,
 * so editing the text brings the bar back for everyone who dismissed the old
 * one. Storage access is guarded: it throws in a private window, and losing a
 * dismissal is not worth a blank page.
 */
export function useAnnouncement(): {
    announcement: ComputedRef<AnnouncementData | null>
    dismiss: () => void
} {
    const payload = bootstrap().announcement
    const dismissed = ref(false)

    const storageKey = payload === null ? null : `panel.announcement.${payload.id}`

    if (storageKey !== null) {
        try {
            dismissed.value = window.localStorage.getItem(storageKey) === '1'
        } catch {
            dismissed.value = false
        }
    }

    function dismiss(): void {
        dismissed.value = true

        if (storageKey === null) {
            return
        }

        try {
            window.localStorage.setItem(storageKey, '1')
        } catch {
            // Remembering is a convenience, not a requirement.
        }
    }

    return {
        announcement: computed(() => (dismissed.value ? null : payload)),
        dismiss,
    }
}

/* -------------------------------------------------------------------------- */
/* Hero                                                                      */
/* -------------------------------------------------------------------------- */

export function useHeroData(props: HeroProps = {}): ComputedRef<HeroData> {
    const { game } = useGame()
    const servers = useServerStore()
    const auth = useAuthStore()
    const { registrationEnabled } = useAccounts()

    return computed<HeroData>(() => {
        const links = game.value.links

        /*
         * Actions point only at routes and links that exist, and only at ones
         * the operator has enabled: "create an account" appears when
         * registration is open, and is replaced by sign-in when it is closed,
         * rather than leading to a page that refuses it.
         */
        const primaryAction: BlockAction | null = auth.isAuthenticated
            ? { label: t('cta.myAccount'), to: '/account' }
            : registrationEnabled.value
              ? { label: t('cta.createAccount'), to: '/register' }
              : { label: t('auth.signIn'), to: '/sign-in' }

        const secondaryAction: BlockAction | null = auth.isAuthenticated
            ? links.downloads
                ? { label: t('cta.download'), href: links.downloads }
                : { label: t('cta.viewRankings'), to: '/rankings/level' }
            : { label: t('auth.signIn'), to: '/sign-in' }

        return {
            title: props.title ?? game.value.name,
            subtitle: props.subtitle ?? game.value.shortName,
            description: props.description ?? game.value.description,
            primaryAction,
            secondaryAction,
            playersOnline: servers.groups.length > 0 ? servers.playersOnline : null,
            serversUp: servers.anyServerUp,
        }
    })
}

/* -------------------------------------------------------------------------- */
/* Server status                                                              */
/* -------------------------------------------------------------------------- */

/**
 * Live server status.
 *
 * Fed by the Reverb broadcast with a polling fallback, both of which are
 * already running in the application's store. A block gets realtime data by
 * reading state, never by opening a socket of its own.
 */
export function useServerStatusData(): ComputedRef<ServerStatusData> {
    const servers = useServerStore()

    return computed<ServerStatusData>(() => {
        const first = servers.groups[0]?.servers[0] ?? null

        return {
            groups: servers.groups,
            processes:
                first === null
                    ? []
                    : [
                          { label: t('server.login'), up: first.login_server_up },
                          { label: t('server.char'), up: first.char_server_up },
                          { label: t('server.map'), up: first.map_server_up },
                      ],
            playersOnline: servers.playersOnline,
            playersPeak: (() => {
                const peaks = servers.groups
                    .flatMap((group) => group.servers)
                    .map((server) => server.players_peak)
                    .filter((peak): peak is number => peak !== null)

                return peaks.length > 0 ? Math.max(...peaks) : null
            })(),
            anyServerUp: servers.anyServerUp,
            woeActive: servers.woeInProgress,
            measuredAt: servers.measuredAt,
            state: {
                loading: servers.loading,
                error: servers.error,
                empty: servers.groups.length === 0,
            },
        }
    })
}

/* -------------------------------------------------------------------------- */
/* Statistics                                                                 */
/* -------------------------------------------------------------------------- */

export function useStatisticsData(): ComputedRef<StatisticsData> {
    const site = useSiteStore()

    onMounted(() => void site.loadStatistics())

    return computed<StatisticsData>(() => {
        const raw = site.statistics

        /*
         * Only the four figures the backend actually measures. Server uptime
         * is absent on purpose: rAthena records no start time the panel can
         * read, so it would have to be invented.
         */
        const items =
            raw === null
                ? []
                : [
                      {
                          key: 'players_online',
                          label: t('stats.playersOnline'),
                          value: raw.players_online,
                      },
                      { key: 'characters', label: t('stats.characters'), value: raw.characters },
                      { key: 'guilds', label: t('stats.guilds'), value: raw.guilds },
                      { key: 'accounts', label: t('stats.accounts'), value: raw.accounts },
                  ].map((item) => ({ ...item, formatted: item.value.toLocaleString() }))

        return {
            items,
            raw,
            state: {
                loading: site.loadingStatistics,
                error: site.statisticsError,
                empty: raw !== null && items.length === 0,
            },
        }
    })
}

/* -------------------------------------------------------------------------- */
/* Class showcase                                                             */
/* -------------------------------------------------------------------------- */

export function useClassShowcaseData(limit = 8): ComputedRef<ClassShowcaseData> {
    const site = useSiteStore()

    onMounted(() => void site.loadClasses(limit))

    return computed<ClassShowcaseData>(() => ({
        classes: site.classes,
        busiest: site.classes.reduce((most, entry) => Math.max(most, entry.characters), 0),
        state: {
            loading: site.loadingClasses,
            error: site.classesError,
            empty: !site.loadingClasses && site.classes.length === 0,
        },
    }))
}

/* -------------------------------------------------------------------------- */
/* Rankings                                                                   */
/* -------------------------------------------------------------------------- */

/**
 * A ladder, fetched for the requested size.
 *
 * Not held in a store: a page may show two ladders at once, and the request is
 * parameterised by both ladder and limit, so caching it globally would mean
 * one block's limit silently deciding another's.
 */
export function useRankingData(
    ladder: RankingLadder = 'level',
    limit = 5,
): ComputedRef<RankingData> {
    const entries = ref<RankingEntry[]>([])
    const loading = ref(false)
    const error = ref<string | null>(null)

    onMounted(async () => {
        loading.value = true
        error.value = null

        try {
            const response = await api.get<{ data: RankingEntry[] }>(`rankings/${ladder}`, {
                limit,
            })
            entries.value = response.data
        } catch {
            error.value = t('rankings.error')
        } finally {
            loading.value = false
        }
    })

    return computed<RankingData>(() => ({
        entries: entries.value,
        ladder,
        state: {
            loading: loading.value,
            error: error.value,
            empty: !loading.value && entries.value.length === 0,
        },
    }))
}

/* -------------------------------------------------------------------------- */
/* News                                                                       */
/* -------------------------------------------------------------------------- */

export function useNewsData(limit = 3): ComputedRef<NewsData> {
    const site = useSiteStore()

    onMounted(() => void site.loadNews(limit))

    return computed<NewsData>(() => ({
        articles: site.news.slice(0, limit),
        state: {
            loading: site.loadingNews,
            error: site.newsError,
            empty: !site.loadingNews && site.news.length === 0,
        },
    }))
}

/* -------------------------------------------------------------------------- */
/* Features                                                                   */
/* -------------------------------------------------------------------------- */

export function useFeatureData(): ComputedRef<FeatureData> {
    const items = bootstrap().features

    return computed<FeatureData>(() => ({
        items,
        state: { loading: false, error: null, empty: items.length === 0 },
    }))
}

/* -------------------------------------------------------------------------- */
/* Downloads                                                                  */
/* -------------------------------------------------------------------------- */

/**
 * What the download page offers, from config/game.php.
 *
 * Nothing is fetched: the packages, the specifications and the steps are
 * operator-authored configuration and arrive in the bootstrap payload with
 * everything else the server decides. There is therefore no loading state to
 * draw and no request to fail -- which is the right trade for a page whose
 * whole job is to hand somebody a link quickly.
 *
 * `empty` is true only when all three sections are, because that is the one
 * case the page as a whole has to answer for. A missing section is the job of
 * the block that would have drawn it.
 */
export function useDownloadsData(): ComputedRef<DownloadsData> {
    const { notice, clients, requirements, steps } = bootstrap().downloads

    return computed<DownloadsData>(() => ({
        notice,
        clients,
        requirements,
        steps,
        state: {
            loading: false,
            error: null,
            empty: clients.length === 0 && requirements.length === 0 && steps.length === 0,
        },
    }))
}

/* -------------------------------------------------------------------------- */
/* Call to action                                                             */
/* -------------------------------------------------------------------------- */

export function useCallToActionData(
    heading?: string,
    description?: string,
): ComputedRef<CallToActionData> {
    const { game } = useGame()
    const auth = useAuthStore()
    const { registrationEnabled } = useAccounts()

    return computed<CallToActionData>(() => {
        const links = game.value.links

        /*
         * As with the hero: every action points somewhere that exists and is
         * enabled. A call to action that 404s, or that leads to a form the
         * server refuses, is worse than none.
         */
        const action: BlockAction | null = auth.isAuthenticated
            ? { label: t('cta.myCharacters'), to: '/characters' }
            : registrationEnabled.value
              ? { label: t('cta.createAccount'), to: '/register' }
              : { label: t('auth.signIn'), to: '/sign-in' }

        /*
         * The operator's own address wins over the panel's page, the same way
         * it does in the navigation: somebody who pointed Download at a CDN
         * means that, not the page they left behind. The internal page is
         * offered next, and only when it has something on it.
         */
        const secondaryAction: BlockAction | null = links.downloads
            ? { label: t('cta.downloadClient'), href: links.downloads }
            : bootstrap().downloads.clients.length > 0
              ? { label: t('cta.downloadClient'), to: '/downloads' }
              : links.discord
                ? { label: t('cta.joinCommunity'), href: links.discord }
                : null

        return {
            title: heading ?? (auth.isAuthenticated ? t('cta.returnTitle') : t('cta.beginTitle')),
            description:
                description ??
                (auth.isAuthenticated
                    ? t('cta.returnBody', { game: game.value.name })
                    : registrationEnabled.value
                      ? t('cta.createBody', { game: game.value.name })
                      : t('cta.signInBody', { game: game.value.name })),
            action,
            secondaryAction,
        }
    })
}
