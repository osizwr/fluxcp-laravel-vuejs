import { onMounted, readonly, ref } from 'vue'

type Theme = 'light' | 'dark'

const STORAGE_KEY = 'panel.theme'

const theme = ref<Theme>('light')

/**
 * Light and dark appearance.
 *
 * The chosen value is written to the document's data-theme attribute, which the
 * stylesheet's token overrides key off, and remembered in localStorage. Reads
 * and writes are guarded because localStorage throws in a private window and
 * when site data is blocked, and losing a theme preference must not break the
 * page.
 */
export function useTheme() {
    function apply(next: Theme): void {
        theme.value = next
        document.documentElement.dataset.theme = next

        try {
            window.localStorage.setItem(STORAGE_KEY, next)
        } catch {
            // A remembered preference is a convenience, not a requirement.
        }
    }

    function toggleTheme(): void {
        apply(theme.value === 'dark' ? 'light' : 'dark')
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

        // No stored preference: follow the operating system rather than
        // imposing one.
        theme.value = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
    })

    return { theme: readonly(theme), toggleTheme }
}
