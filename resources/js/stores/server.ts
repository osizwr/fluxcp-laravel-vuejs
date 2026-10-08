import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../services/api'
import type { ServerGroupStatus, ServerStatusResponse } from '../types/api'
import { translate as t } from '../i18n'

/**
 * Live status of the game servers.
 *
 * Updated by a broadcast from the server when Reverb is configured, and by
 * polling otherwise. The poll interval follows the cache window the API
 * reports, since there is no point asking faster than the figures can change.
 */
export const useServerStore = defineStore('server', () => {
    const groups = ref<ServerGroupStatus[]>([])
    const playersOnline = ref(0)
    const measuredAt = ref<string | null>(null)
    const cacheSeconds = ref(30)
    const loading = ref(false)
    const error = ref<string | null>(null)

    const anyServerUp = computed(() =>
        groups.value.some((group) => group.servers.some((server) => server.playable)),
    )

    const woeInProgress = computed(() =>
        groups.value.some((group) => group.servers.some((server) => server.woe_active)),
    )

    function apply(payload: ServerStatusResponse): void {
        groups.value = payload.data
        playersOnline.value = payload.meta.players_online
        measuredAt.value = payload.meta.measured_at
        cacheSeconds.value = payload.meta.cache_seconds
    }

    async function load(): Promise<void> {
        loading.value = true
        error.value = null

        try {
            apply(await api.get<ServerStatusResponse>('server/status'))
        } catch {
            error.value = t('server.error')
        } finally {
            loading.value = false
        }
    }

    return {
        groups,
        playersOnline,
        measuredAt,
        cacheSeconds,
        loading,
        error,
        anyServerUp,
        woeInProgress,
        apply,
        load,
    }
})
