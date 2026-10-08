import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../services/api'
import type { ClassDistributionEntry, NewsArticle, ServerStatistics } from '../types/api'
import { translate as t } from '../i18n'

/**
 * Site-wide data the front-page blocks consume.
 *
 * Here rather than in the blocks because several blocks may want the same
 * figures, and because a block that fetched for itself would fire a request per
 * instance -- and would be doing data access, which themes are not allowed to
 * do.
 *
 * Each section loads at most once per page load. The backend already caches
 * these, so a second request would be wasted on both sides.
 */
export const useSiteStore = defineStore('site', () => {
    const statistics = ref<ServerStatistics | null>(null)
    const classes = ref<ClassDistributionEntry[]>([])
    const news = ref<NewsArticle[]>([])

    const loadingStatistics = ref(false)
    const loadingClasses = ref(false)
    const loadingNews = ref(false)

    const statisticsError = ref<string | null>(null)
    const classesError = ref<string | null>(null)
    const newsError = ref<string | null>(null)

    let statisticsLoaded = false
    let classesLoaded = false
    let newsLoaded = 0

    async function loadStatistics(): Promise<void> {
        if (statisticsLoaded || loadingStatistics.value) {
            return
        }

        loadingStatistics.value = true
        statisticsError.value = null

        try {
            const response = await api.get<{ data: ServerStatistics }>('server/statistics')
            statistics.value = response.data
            statisticsLoaded = true
        } catch {
            statisticsError.value = t('stats.error')
        } finally {
            loadingStatistics.value = false
        }
    }

    async function loadClasses(limit = 8): Promise<void> {
        if (classesLoaded || loadingClasses.value) {
            return
        }

        loadingClasses.value = true
        classesError.value = null

        try {
            const response = await api.get<{ data: ClassDistributionEntry[] }>(
                'characters/classes',
                { limit },
            )
            classes.value = response.data
            classesLoaded = true
        } catch {
            classesError.value = t('classes.error')
        } finally {
            loadingClasses.value = false
        }
    }

    /**
     * @param limit How many articles are wanted. A later request for more
     *              articles than are loaded refetches; asking for fewer does
     *              not, since the extra rows are harmless.
     */
    async function loadNews(limit = 3): Promise<void> {
        if (newsLoaded >= limit || loadingNews.value) {
            return
        }

        loadingNews.value = true
        newsError.value = null

        try {
            const response = await api.get<{ data: NewsArticle[] }>('news', { per_page: limit })
            news.value = response.data
            newsLoaded = limit
        } catch {
            newsError.value = t('news.error')
        } finally {
            loadingNews.value = false
        }
    }

    return {
        statistics,
        classes,
        news,
        loadingStatistics,
        loadingClasses,
        loadingNews,
        statisticsError,
        classesError,
        newsError,
        loadStatistics,
        loadClasses,
        loadNews,
    }
})
