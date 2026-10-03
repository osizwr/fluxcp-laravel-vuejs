<script setup lang="ts">
import StateBlock from '../components/ui/StateBlock.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { useServerStatusData } from './data'
import type { ServerStatusProps } from './contracts'

/**
 * The core server status block.
 *
 * Reads the application's store, which is fed by the Reverb broadcast with a
 * polling fallback, so this is realtime without the block knowing a socket
 * exists.
 *
 * All three emulator processes are shown separately: which one is down tells a
 * player whether to wait or to report it.
 */
const props = withDefaults(defineProps<ServerStatusProps>(), {
    heading: 'Server status',
    detailed: false,
})

const status = useServerStatusData()
</script>

<template>
    <section class="mx-auto max-w-6xl px-4 py-10" aria-labelledby="block-server-status">
        <h2 id="block-server-status" class="mb-4 text-lg font-semibold">{{ props.heading }}</h2>

        <StateBlock
            v-if="status.state.error"
            variant="error"
            title="Status unavailable"
            :description="status.state.error"
        />
        <StateBlock
            v-else-if="status.state.loading && status.state.empty"
            variant="loading"
            title="Checking servers…"
        />

        <template v-else>
            <div class="panel grid gap-px overflow-hidden bg-[var(--border-subtle)] sm:grid-cols-4">
                <div
                    v-for="process in status.processes"
                    :key="process.label"
                    class="bg-[var(--surface-raised)] px-4 py-3.5 text-center"
                >
                    <p class="text-[0.75rem] tracking-wide text-[var(--text-muted)] uppercase">
                        {{ process.label }}
                    </p>
                    <p class="mt-1.5 flex justify-center">
                        <StatusPill
                            :state="process.up ? 'up' : 'down'"
                            :label="process.up ? 'Online' : 'Offline'"
                        />
                    </p>
                </div>

                <div class="bg-[var(--surface-raised)] px-4 py-3.5 text-center">
                    <p class="text-[0.75rem] tracking-wide text-[var(--text-muted)] uppercase">
                        Players
                    </p>
                    <p class="tabular mt-1.5 text-xl font-semibold">
                        {{ status.playersOnline.toLocaleString() }}
                    </p>
                </div>
            </div>

            <!-- Every configured world, when the composition asks for detail. -->
            <div v-if="props.detailed" class="mt-3 grid gap-3 md:grid-cols-2">
                <article
                    v-for="server in status.groups.flatMap((group) => group.servers)"
                    :key="server.key"
                    class="panel p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold">{{ server.name }}</h3>
                            <p class="tabular text-sm text-[var(--text-secondary)]">
                                {{ server.players_online.toLocaleString() }} online
                                <template v-if="server.players_peak !== null">
                                    · peak {{ server.players_peak.toLocaleString() }}
                                </template>
                            </p>
                        </div>
                        <StatusPill
                            :state="server.playable ? 'up' : 'down'"
                            :label="server.playable ? 'Playable' : 'Unavailable'"
                        />
                    </div>
                </article>
            </div>

            <p v-if="status.measuredAt" class="mt-3 text-[0.8125rem] text-[var(--text-muted)]">
                Measured {{ new Date(status.measuredAt).toLocaleTimeString() }}
            </p>
        </template>
    </section>
</template>
