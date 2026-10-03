import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

export interface ShellLink {
    to: string
    label: string
}

/**
 * Everything the application shell needs to behave, with nothing about how it
 * looks.
 *
 * This exists so a theme's layout can be purely presentational. Without it,
 * every theme would copy the navigation list, the active-route matching and
 * the sign-out sequence, and the fourth theme would be the one that forgot to
 * clear the session properly.
 *
 * A theme consumes this and decides only what the shell looks like.
 */
export function useShell(): {
    links: ComputedRef<ShellLink[]>
    isActive: (to: string) => boolean
    menuOpen: Ref<boolean>
    signingOut: Ref<boolean>
    signOut: () => Promise<void>
} {
    const auth = useAuthStore()
    const route = useRoute()
    const router = useRouter()

    const menuOpen = ref(false)
    const signingOut = ref(false)

    /**
     * Only routes that exist, and only those the viewer can reach.
     *
     * Built from the authenticated state rather than rendered-and-hidden, so
     * the markup never contains a link to a page that would bounce the
     * visitor.
     */
    const links = computed<ShellLink[]>(() => [
        { to: '/', label: 'Status' },
        { to: '/rankings/level', label: 'Rankings' },
        { to: '/who-is-online', label: "Who's online" },
        ...(auth.isAuthenticated
            ? [
                  { to: '/characters', label: 'Characters' },
                  { to: '/account', label: 'Account' },
              ]
            : []),
    ])

    function isActive(to: string): boolean {
        if (to === '/') {
            return route.path === '/'
        }

        // Matched on the first path segment so /rankings/zeny still marks the
        // Rankings item as current.
        return route.path.startsWith(to.split('/').slice(0, 2).join('/'))
    }

    async function signOut(): Promise<void> {
        signingOut.value = true

        try {
            await auth.logout()
            await router.push({ name: 'home' })
        } finally {
            signingOut.value = false
            menuOpen.value = false
        }
    }

    return { links, isActive, menuOpen, signingOut, signOut }
}
