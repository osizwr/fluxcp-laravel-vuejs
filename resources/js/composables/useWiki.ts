import { onBeforeUnmount, readonly, ref, type DeepReadonly, type Ref } from 'vue'
import { api } from '../services/api'
import { translate as t } from '../i18n'
import type { WikiIndex, WikiSearchResult } from '../types/api'

/**
 * The wiki's table of contents, and searching it.
 *
 * -------------------------------------------------------------------------
 * Why the index is held in module scope
 * -------------------------------------------------------------------------
 *
 * Every article renders the same sidebar, so a per-component fetch would
 * re-request the whole table of contents on each navigation -- the one request
 * guaranteed to produce an identical answer. It is fetched once and shared,
 * and a second caller arriving while the first is still in flight waits on the
 * same promise rather than starting another.
 *
 * It is not reactive to the server: the wiki changes when somebody deploys a
 * file, not while a visitor is reading. A full page load picks up the change,
 * which is what a deploy causes anyway.
 */

const index = ref<WikiIndex | null>(null)
const error = ref<string | null>(null)

/** The request in flight, so concurrent callers share one. */
let inflight: Promise<void> | null = null

async function fetchIndex(): Promise<void> {
    try {
        const response = await api.get<{ data: WikiIndex }>('wiki')

        index.value = response.data
        error.value = null
    } catch {
        /*
         * A failure here is usually not a failure: an operator who keeps
         * their guide elsewhere turns the wiki off, and the endpoint then
         * 404s. The page says the wiki is unavailable either way, because
         * from a visitor's side "turned off" and "broken" are the same
         * sentence -- there is nothing to read.
         */
        index.value = null
        error.value = t('wiki.unavailable')
    } finally {
        inflight = null
    }
}

/**
 * The table of contents, fetched once per page load.
 */
export function useWikiIndex(): {
    index: Readonly<Ref<WikiIndex | null>>
    loading: Readonly<Ref<boolean>>
    error: Readonly<Ref<string | null>>
    load: () => Promise<void>
} {
    const loading = ref(index.value === null)

    async function load(): Promise<void> {
        if (index.value !== null) {
            loading.value = false

            return
        }

        loading.value = true
        inflight ??= fetchIndex()

        await inflight

        loading.value = false
    }

    return {
        index: readonly(index) as Readonly<Ref<WikiIndex | null>>,
        loading: readonly(loading),
        error: readonly(error),
        load,
    }
}

/**
 * The search box.
 *
 * Debounced, because a keystroke is not a question: typing "rates" would
 * otherwise ask the server five times and race the answers against each
 * other. The in-flight request is aborted when a newer one starts, so the
 * results shown are always the ones for what is currently in the box.
 */
export function useWikiSearch(): {
    term: Ref<string>
    results: DeepReadonly<Ref<WikiSearchResult[]>>
    searching: Readonly<Ref<boolean>>
    /** True once a search has actually run, so "no results" is not shown before one has. */
    searched: Readonly<Ref<boolean>>
    search: (value: string) => void
    clear: () => void
} {
    const term = ref('')
    const results = ref<WikiSearchResult[]>([])
    const searching = ref(false)
    const searched = ref(false)

    let timer: ReturnType<typeof setTimeout> | null = null
    let controller: AbortController | null = null

    function clear(): void {
        if (timer !== null) {
            clearTimeout(timer)
            timer = null
        }

        controller?.abort()
        controller = null

        term.value = ''
        results.value = []
        searching.value = false
        searched.value = false
    }

    function search(value: string): void {
        term.value = value

        if (timer !== null) {
            clearTimeout(timer)
        }

        // Shorter than the minimum the server will answer. Cleared rather
        // than left showing the results for a longer word that has just been
        // deleted back to one letter.
        if (value.trim().length < 2) {
            controller?.abort()
            controller = null
            results.value = []
            searching.value = false
            searched.value = false

            return
        }

        searching.value = true

        timer = setTimeout(() => {
            void run(value)
        }, 250)
    }

    async function run(value: string): Promise<void> {
        controller?.abort()
        controller = new AbortController()

        const signal = controller.signal

        try {
            const response = await api.get<{ data: WikiSearchResult[] }>(
                'wiki/search',
                { q: value },
                signal,
            )

            results.value = response.data
            searched.value = true
        } catch {
            // An abort lands here too, and an aborted search has not failed:
            // the one that replaced it is about to answer. Either way the
            // box shows no results rather than an error, because a search
            // that found nothing and a search that broke look the same to
            // somebody who just wants the page they were after.
            if (!signal.aborted) {
                results.value = []
                searched.value = true
            }
        } finally {
            if (!signal.aborted) {
                searching.value = false
            }
        }
    }

    onBeforeUnmount(() => {
        if (timer !== null) {
            clearTimeout(timer)
        }

        controller?.abort()
    })

    return {
        term,
        results: readonly(results),
        searching: readonly(searching),
        searched: readonly(searched),
        search,
        clear,
    }
}

/**
 * The route for a wiki path.
 *
 * One place, so a change of prefix is one edit rather than a search for
 * string concatenation across three components.
 */
export function wikiRoute(path: string): string {
    return `/wiki/${path}`
}

/**
 * A date as a reader expects to see it, in their own locale.
 *
 * Day precision: these are "this page was revised in October", not timestamps,
 * and showing a time to the second invites a precision the file's own
 * modification date does not have.
 */
export function wikiDate(value: string | null): string {
    if (value === null) {
        return ''
    }

    const at = new Date(value)

    return Number.isNaN(at.getTime())
        ? ''
        : at.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
