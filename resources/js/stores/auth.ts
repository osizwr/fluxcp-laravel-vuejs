import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError } from '../services/api'
import type { Account } from '../types/api'

interface LoginCredentials {
    username: string
    password: string
    server?: string
    remember?: boolean
}

/**
 * The signed-in account.
 *
 * The permission list is held here so components can hide controls the viewer
 * cannot use. It is a convenience for the interface only -- every one of these
 * is enforced again on the server, and hiding a control is never what stops an
 * action.
 */
export const useAuthStore = defineStore('auth', () => {
    const account = ref<Account | null>(null)
    const permissions = ref<string[]>([])
    const resolved = ref(false)

    const isAuthenticated = computed(() => account.value !== null)
    const isStaff = computed(() => account.value?.group.is_staff ?? false)

    /**
     * Whether the viewer may open the dashboard.
     *
     * 99 is AccountLevel::Administrator, which config/permissions.php reaches
     * from rAthena's group 99 by default. Compared against the *level* the
     * server resolved rather than against `group.id`, so an operator who has
     * given another group administrator rights in that file gets a dashboard
     * link that agrees with the API instead of one that 403s.
     *
     * Like everything else here, this only decides what is drawn. The
     * endpoints behind the dashboard each check again.
     */
    const isAdministrator = computed(() => (account.value?.group.level ?? -1) >= 99)

    function can(ability: string): boolean {
        return permissions.value.includes(ability)
    }

    function set(payload: { data: Account; meta?: { permissions?: string[] } }): void {
        account.value = payload.data
        permissions.value = payload.meta?.permissions ?? permissions.value
    }

    function clear(): void {
        account.value = null
        permissions.value = []
    }

    async function login(credentials: LoginCredentials): Promise<void> {
        set(await api.post('auth/login', credentials))
        // The sign-in response does not carry permissions, so they are fetched
        // immediately afterwards.
        await refresh()
    }

    /**
     * Sign in at /admin/login.
     *
     * A different endpoint rather than a flag on the usual one: it refuses a
     * non-administrator *before* creating a session, so posting this form with
     * a player's credentials does not quietly sign that player in. Every
     * failure comes back as the same message, so there is nothing here to tell
     * one apart from another.
     */
    async function adminLogin(credentials: LoginCredentials): Promise<void> {
        set(await api.post('admin/session', credentials))
        await refresh()
    }

    async function logout(): Promise<void> {
        try {
            await api.post('auth/logout')
        } finally {
            // Clear locally regardless. If the session had already expired the
            // request fails, and leaving stale account data on screen would be
            // worse than the failed call.
            clear()
        }
    }

    /**
     * Load the current account, if there is a session.
     *
     * Called once when the application starts. A 401 is the expected answer for
     * a visitor who is not signed in, not an error.
     */
    async function refresh(): Promise<void> {
        try {
            set(await api.get('account', { with_permissions: 1 }))
        } catch (error) {
            if (error instanceof ApiError && error.isUnauthenticated) {
                clear()
            } else {
                throw error
            }
        } finally {
            resolved.value = true
        }
    }

    return {
        account,
        permissions,
        resolved,
        isAuthenticated,
        isStaff,
        isAdministrator,
        can,
        login,
        adminLogin,
        logout,
        refresh,
        clear,
    }
})
