import { computed, readonly, ref, type ComputedRef, type Ref } from 'vue'

/**
 * Downloads a theme's heavy media up front, reporting progress in bytes.
 *
 * This exists because a landing page built around a video has a problem the
 * browser will not solve on its own: the markup renders in milliseconds and the
 * artwork arrives seconds later, so the first thing a visitor sees is the page
 * with a hole in it. A gate that holds the page back until the media is here
 * trades a wait the visitor can see for one they cannot.
 *
 * It lives in core rather than in a theme for the reason every theme rule in
 * this project exists: a theme is presentation, and a test fails if a theme
 * file so much as mentions a network call. A theme declares *what* to preload
 * and draws the screen; this decides how, and what counts as finished.
 *
 * Two things make the progress real rather than decorative:
 *
 *   - Each response is read as a stream, so `loaded` is the bytes that have
 *     actually arrived, not a timer pretending to be one.
 *   - `total` is the sum of the `Content-Length` headers. It is unknown until
 *     the first response's headers land, and a server that omits the header
 *     leaves that asset out of the total rather than guessing at it.
 *
 * The bytes are kept. Each completed download becomes an object URL that the
 * block which needs it asks for by its original path, so the media is served
 * from memory instead of being requested a second time -- which is what makes
 * this a preload rather than a progress bar in front of the same work happening
 * twice. That does mean holding the file in memory, so what goes in the
 * manifest should be the handful of things worth waiting for.
 *
 * It fails open, always. A 404, a refused connection, a server with no
 * `Content-Length`, a visitor on a connection too slow to finish before the
 * deadline -- each resolves to "stop waiting and show the page", because the
 * blocks all fall back to their own paths and a gate that can strand somebody
 * on a loading screen is worse than no gate.
 */

export interface PreloadTarget {
    /** Same-origin path, exactly as the block requesting it will ask for it. */
    url: string
}

export interface PreloadOptions {
    /**
     * How long to hold the page back, in milliseconds.
     *
     * Not a request timeout: it is the longest a visitor should stare at a
     * loading screen before being let in regardless. Downloads still in flight
     * are abandoned and the block falls back to streaming its own source.
     */
    deadline?: number
}

/*
 * Module scope, deliberately.
 *
 * The gate is a once-per-page-load event, not a once-per-mount one. Holding
 * this inside the composable would re-run the whole download every time a
 * layout remounted -- navigating away from the landing page and back would
 * show the loading screen again, which is exactly the behaviour that makes
 * preloaders infuriating.
 */
const loaded = ref(0)
const total = ref(0)
const settled = ref(false)
const failures = ref<string[]>([])
const resolved = ref<Record<string, string>>({})

let started = false
let revealClaimed = false

/**
 * The preload's current state, without starting one.
 *
 * Separate from `usePreload` because starting is a side effect and the layout
 * that wants to know whether the gate is still up must not be the thing that
 * decides there is nothing to wait for. Calling `usePreload([])` to read the
 * state would mark the preload started with an empty manifest, and whichever
 * component ran first would win -- a race decided by component order, which is
 * the worst kind.
 */
export function preloadState(): {
    loaded: Readonly<Ref<number>>
    total: Readonly<Ref<number>>
    percent: ComputedRef<number>
    settled: Readonly<Ref<boolean>>
    failures: Readonly<Ref<readonly string[]>>
    loadedLabel: ComputedRef<string>
    totalLabel: ComputedRef<string>
} {
    return {
        loaded: readonly(loaded),
        total: readonly(total),
        percent: computed(() =>
            total.value <= 0 ? 0 : Math.min(100, Math.round((loaded.value / total.value) * 100)),
        ),
        settled: readonly(settled),
        failures: readonly(failures),
        loadedLabel: computed(() => formatBytes(loaded.value)),
        totalLabel: computed(() => formatBytes(total.value)),
    }
}

/**
 * Whether the caller owns the one-time reveal, answered once per page load.
 *
 * The landing page fades in behind the gate as it clears. That is an arrival,
 * not a transition, so it belongs to the first paint and not to every later
 * visit: a visitor who leaves the front page and comes back has already
 * arrived, and replaying the fade would make the site feel like it had
 * reloaded when it had not.
 */
export function claimReveal(): boolean {
    if (revealClaimed) {
        return false
    }

    revealClaimed = true

    return true
}

/**
 * The object URL for a preloaded path, or the path itself.
 *
 * Callers do not need to know whether the preload ran, succeeded, or was
 * skipped: they ask for the path they would have used anyway and get back
 * whatever is currently the cheapest way to obtain it.
 */
export function preloadedUrl(url: string): string {
    return resolved.value[url] ?? url
}

/** `44348943` -> `42.3 MB`. Binary units, because that is what a browser reports. */
export function formatBytes(bytes: number): string {
    if (bytes <= 0) {
        return '0 MB'
    }

    const mb = bytes / 1024 / 1024

    return mb < 1 ? `${Math.round(bytes / 1024)} KB` : `${mb.toFixed(1)} MB`
}

/**
 * Reads one response to completion, adding each chunk to the running total.
 *
 * The chunks are retained so the finished file can be handed back as an object
 * URL. A response with no readable body -- which is what a cached opaque
 * response looks like -- resolves without contributing, rather than hanging.
 */
async function drain(target: PreloadTarget, signal: AbortSignal): Promise<void> {
    const response = await window.fetch(target.url, { signal, credentials: 'same-origin' })

    if (!response.ok) {
        throw new Error(`${response.status} for ${target.url}`)
    }

    const declared = Number(response.headers.get('content-length') ?? '')

    if (Number.isFinite(declared) && declared > 0) {
        total.value += declared
    }

    if (response.body === null) {
        // Nothing to measure; take the whole thing and count it once.
        const blob = await response.blob()
        loaded.value += blob.size
        resolved.value = { ...resolved.value, [target.url]: URL.createObjectURL(blob) }

        return
    }

    const reader = response.body.getReader()
    const chunks: Uint8Array[] = []

    for (;;) {
        const { done, value } = await reader.read()

        if (done) {
            break
        }

        chunks.push(value)
        loaded.value += value.byteLength
    }

    const type = response.headers.get('content-type') ?? 'application/octet-stream'
    const blob = new Blob(chunks as BlobPart[], { type })

    resolved.value = { ...resolved.value, [target.url]: URL.createObjectURL(blob) }
}

/**
 * Preload a manifest, once per page load.
 *
 * @param targets What to fetch. Order is irrelevant; they go in parallel, so
 *                the total settles as the headers arrive rather than after the
 *                first file finishes.
 */
export function usePreload(
    targets: PreloadTarget[] = [],
    options: PreloadOptions = {},
): {
    loaded: Readonly<Ref<number>>
    total: Readonly<Ref<number>>
    /** 0-100. Reports 0 rather than NaN while the total is still unknown. */
    percent: ComputedRef<number>
    settled: Readonly<Ref<boolean>>
    failures: Readonly<Ref<readonly string[]>>
    loadedLabel: ComputedRef<string>
    totalLabel: ComputedRef<string>
} {
    const percent = computed(() => {
        if (total.value <= 0) {
            return 0
        }

        return Math.min(100, Math.round((loaded.value / total.value) * 100))
    })

    if (!started) {
        started = true

        if (targets.length === 0) {
            settled.value = true
        } else {
            const controller = new AbortController()

            const deadline = window.setTimeout(() => {
                if (!settled.value) {
                    /*
                     * Abandon rather than let it run on: the block is about to
                     * request the same file itself, and two downloads of the
                     * same video is the one outcome worse than waiting for one.
                     */
                    controller.abort()
                    failures.value = [...failures.value, 'deadline']
                    settled.value = true
                }
            }, options.deadline ?? 20000)

            void Promise.allSettled(
                targets.map((target) =>
                    drain(target, controller.signal).catch((error: unknown) => {
                        failures.value = [
                            ...failures.value,
                            error instanceof Error ? error.message : target.url,
                        ]
                    }),
                ),
            ).then(() => {
                window.clearTimeout(deadline)
                settled.value = true
            })
        }
    }

    return {
        loaded: readonly(loaded),
        total: readonly(total),
        percent,
        settled: readonly(settled),
        failures: readonly(failures),
        loadedLabel: computed(() => formatBytes(loaded.value)),
        totalLabel: computed(() => formatBytes(total.value)),
    }
}
