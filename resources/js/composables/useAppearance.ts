import { nextTick, readonly, ref, type Ref } from 'vue'
import { bootstrap } from '../theme/bootstrap'

export type Appearance = 'light' | 'dark'

const STORAGE_KEY = 'panel.appearance'

const appearance = ref<Appearance>('light')

/**
 * Light and dark appearance.
 *
 * Distinct from the theme (the skin), which is chosen by the server from
 * APP_THEME and arrives in the bootstrap payload. This is only how bright the
 * interface is, and it is the visitor's choice rather than the operator's.
 *
 * The chosen value is written to the document's data-appearance attribute,
 * which the stylesheet's token overrides key off, and remembered in
 * localStorage. Reads and writes are guarded because localStorage throws in a
 * private window and when site data is blocked, and losing a theme preference
 * must not break the page.
 *
 * ---------------------------------------------------------------------------
 * Resolved once, during setup, not on mount
 * ---------------------------------------------------------------------------
 *
 * It used to be settled in `onMounted`, which was a frame too late in two
 * ways. The visible one was a flash: the page painted light and then corrected
 * itself for a visitor who had chosen dark. The one that actually forced this
 * change is that a theme may need to *choose an asset* by appearance -- Skyward
 * fetches a different hero video after dark -- and the preload manifest is
 * built in a layout's setup, before any child has mounted. Asking then would
 * have got the default rather than the answer.
 *
 * So the resolution happens on the first call, synchronously, and is shared
 * from module scope. There is no server rendering here, so `document` and the
 * bootstrap payload are both available by then.
 */

/** Whether the stored-or-default resolution has already run this page load. */
let resolved = false

function storedPreference(): string | null {
    try {
        return window.localStorage.getItem(STORAGE_KEY)
    } catch {
        // Private windows and blocked site data both throw here.
        return null
    }
}

/**
 * @param persist Whether this is the visitor's own choice. A default
 *                inherited from the theme is not persisted: storing it
 *                would make it outlive the theme that suggested it and
 *                override the next theme's art direction.
 */
function apply(next: Appearance, persist = true): void {
    appearance.value = next
    document.documentElement.dataset.appearance = next

    if (!persist) {
        return
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, next)
    } catch {
        // A remembered preference is a convenience, not a requirement.
    }
}

/**
 * Settle on an appearance: the visitor's, then the theme's, then the system's.
 *
 * Idempotent, and cheap after the first call, so anything that needs the
 * answer can simply ask rather than having to know whether something else has
 * asked already.
 */
function resolveOnce(): void {
    if (resolved) {
        return
    }

    resolved = true

    const stored = storedPreference()

    if (stored === 'light' || stored === 'dark') {
        apply(stored)

        return
    }

    /*
     * No stored preference. A theme art-directed for one appearance says so
     * in its theme.json and wins here, because showing a dark fantasy skin in
     * its light variant on a first visit is showing it at its worst -- and
     * because Skyward, which has both, is a daylight design that happens to
     * also work after dark rather than one that should open either way.
     *
     * apply() is used rather than a bare assignment so the attribute and the
     * toggle agree with what is actually rendered.
     */
    const preferred = bootstrap().theme.defaultAppearance

    if (preferred !== null) {
        apply(preferred, false)

        return
    }

    // Neutral theme: the operating system decides. Not persisted either, so
    // a visitor who later changes that setting is followed rather than
    // pinned to whatever it said the first time.
    apply(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light', false)
}

/* -------------------------------------------------------------------------- */
/* Changing it                                                                */
/* -------------------------------------------------------------------------- */

/**
 * The browsers that can cross-fade a whole page for us.
 *
 * Declared locally rather than pulled from a DOM lib: the typings for this
 * vary by TypeScript version, and all that is wanted here is the one call and
 * the one promise.
 */
type ViewTransitionDocument = Document & {
    startViewTransition?: (callback: () => void | Promise<void>) => { finished: Promise<void> }
}

/** True while a change is being animated; nothing may start a second one. */
const changing = ref(false)

/**
 * How long the fade runs, read from CSS rather than kept here as a number.
 *
 * The animation itself is declared in the stylesheet -- it has to be, because
 * `::view-transition-old` is not something script can time -- so the duration
 * lives there too and this reads it back. Keeping a second copy in
 * JavaScript would mean a theme retuning the fade got a disabled window that
 * no longer matched it, and the button would come back before the page had
 * finished changing underneath it.
 */
function fadeDuration(): number {
    const raw = window
        .getComputedStyle(document.documentElement)
        .getPropertyValue('--appearance-fade')
        .trim()

    const value = Number.parseFloat(raw)

    if (Number.isNaN(value)) {
        return 2000
    }

    return raw.endsWith('ms') ? value : value * 1000
}

function prefersReducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Change the appearance, slowly and visibly.
 *
 * Three paths, in order of how good the result is:
 *
 *   1. **View transitions.** The browser snapshots the page, we swap the
 *      tokens, and it cross-fades the two paints. This is the only one of
 *      the three that fades *everything* -- including the hero swapping to a
 *      different video, which no amount of CSS transition can blend because
 *      it is one element being replaced by another.
 *
 *      The callback awaits `nextTick`. Setting the attribute restyles the
 *      page synchronously, but anything Vue draws from the appearance -- that
 *      video, the icon in the button -- is applied on the next tick, and
 *      without waiting those land *after* the snapshot and pop into place
 *      once the fade has finished.
 *
 *   2. **A blanket transition**, for browsers without the above. Every
 *      element is given a colour transition for the length of the change and
 *      it is taken away afterwards, so the rule is not sitting on the page
 *      slowing down every ordinary hover for the rest of the session.
 *
 *   3. **Instantly**, when the visitor has asked for reduced motion. A page
 *      changing colour is the content changing, not decoration, so it still
 *      happens -- it just does not take a second and a fifth to do it.
 *
 * Re-entry is refused rather than queued. Two fades overlapping leaves the
 * page mid-cross-fade with no third state to settle into, and the button is
 * disabled for the duration anyway -- this guards the keyboard and the
 * double-click, not the careless caller.
 */
async function change(next: Appearance): Promise<void> {
    if (changing.value) {
        return
    }

    if (prefersReducedMotion()) {
        apply(next)

        return
    }

    changing.value = true

    try {
        const doc = document as ViewTransitionDocument

        if (typeof doc.startViewTransition === 'function') {
            await doc.startViewTransition(async () => {
                apply(next)
                await nextTick()
            }).finished

            return
        }

        const root = document.documentElement

        root.classList.add('appearance-fading')
        apply(next)

        await wait(fadeDuration())

        root.classList.remove('appearance-fading')
    } finally {
        changing.value = false
    }
}

export function useAppearance(): {
    appearance: Readonly<Ref<Appearance>>
    /** True while a change is fading; a toggle should refuse input and say so. */
    changing: Readonly<Ref<boolean>>
    toggleAppearance: () => void
} {
    resolveOnce()

    return {
        appearance: readonly(appearance),
        changing: readonly(changing),
        toggleAppearance: () => {
            void change(appearance.value === 'dark' ? 'light' : 'dark')
        },
    }
}

/**
 * The appearance, for code that is not a component.
 *
 * Same resolution, same shared value; this exists so a layout building a
 * preload manifest does not have to pretend to be a consumer of the toggle.
 */
export function currentAppearance(): Appearance {
    resolveOnce()

    return appearance.value
}
