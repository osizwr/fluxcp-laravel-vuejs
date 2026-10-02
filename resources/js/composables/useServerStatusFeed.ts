import { onUnmounted } from 'vue'
import { useServerStore } from '../stores/server'
import { subscribeToPublicChannel } from '../services/broadcasting'
import type { ServerStatusResponse } from '../types/api'

/**
 * Keeps server status current for the lifetime of the page.
 *
 * Prefers a broadcast from Reverb, and falls back to polling when broadcasting
 * is not configured or the socket cannot connect. The fallback matters: an
 * operator who has not set up Reverb should still get a working status page,
 * not a figure frozen at page load.
 *
 * The poll interval follows the cache window the API reports, because there is
 * no point asking more often than the figures can change.
 */
export function useServerStatusFeed() {
    const servers = useServerStore()

    let pollTimer: number | null = null
    let unsubscribe: (() => void) | null = null

    function stopPolling(): void {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer)
            pollTimer = null
        }
    }

    function startPolling(): void {
        stopPolling()

        const seconds = Math.max(servers.cacheSeconds, 10)

        pollTimer = window.setInterval(() => {
            // Pausing while the tab is hidden keeps a backgrounded tab from
            // polling all day.
            if (document.visibilityState === 'visible') {
                void servers.load()
            }
        }, seconds * 1000)
    }

    async function start(): Promise<void> {
        await servers.load()

        unsubscribe = subscribeToPublicChannel<ServerStatusResponse>(
            'server-status',
            '.server.status.updated',
            (payload) => servers.apply(payload),
            {
                // Broadcasting unavailable, or the socket dropped: fall back
                // rather than silently stop updating.
                onUnavailable: startPolling,
                onConnected: stopPolling,
            },
        )

        if (unsubscribe === null) {
            startPolling()
        }
    }

    onUnmounted(() => {
        stopPolling()
        unsubscribe?.()
    })

    return { start }
}
