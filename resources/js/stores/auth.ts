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
        can,
        login,
        logout,
        refresh,
        clear,
    }
})
