import { onMounted, readonly, ref } from 'vue'
import { bootstrap } from '../theme/bootstrap'

type Appearance = 'light' | 'dark'

const STORAGE_KEY = 'panel.appearance'

const appearance = ref<Appearance>('light')

/**
 * Light and dark appearance.
 *
 * Distinct from the theme (the skin), which is chosen by the server from
 * APP_THEME and arrives in the bootstrap payload. This is only how bright the
 * interface is, and it is the visitor's choice rather than the operator's.
 *
 * The chosen value is written to the document's data-theme attribute, which the
 * stylesheet's token overrides key off, and remembered in localStorage. Reads
 * and writes are guarded because localStorage throws in a private window and
 * when site data is blocked, and losing a theme preference must not break the
 * page.
 */
export function useAppearance() {
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

    function toggleAppearance(): void {
        apply(appearance.value === 'dark' ? 'light' : 'dark')
    }

    function storedPreference(): string | null {
        try {
            return window.localStorage.getItem(STORAGE_KEY)
        } catch {
            // Private windows and blocked site data both throw here.
            return null
        }
    }

    onMounted(() => {
        const stored = storedPreference()

        if (stored === 'light' || stored === 'dark') {
            apply(stored)

            return
        }

        /*
         * No stored preference. A theme art-directed for one appearance says
         * so in its theme.json and wins here, because showing a dark fantasy
         * skin in its light variant on a first visit is showing it at its
         * worst. Only when the theme is neutral does the operating system
         * decide.
         *
         * apply() is used rather than a bare assignment so the attribute and
         * the toggle agree with what is actually rendered.
         */
        const preferred = bootstrap().theme.defaultAppearance

        if (preferred !== null) {
            apply(preferred, false)

            return
        }

        appearance.value = window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light'
    })

    return { appearance: readonly(appearance), toggleAppearance }
}
