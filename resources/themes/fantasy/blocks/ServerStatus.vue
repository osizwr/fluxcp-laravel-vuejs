<script setup lang="ts">
import StateBlock from '@/components/ui/StateBlock.vue'
import { useServerStatusData } from '@/blocks/data'
import type { ServerStatusProps } from '@/blocks/contracts'

/**
 * Fantasy — server status.
 *
 * The figures are measured by the backend: reachability from a real TCP
 * connection to each emulator process, player counts from rAthena's own
 * `char.online` column. Nothing here is simulated.
 *
 * Realtime arrives through the application's store, which the Reverb
 * broadcast feeds with a polling fallback. This block opens no socket of its
 * own -- it reads state, which is what keeps the theme out of the websocket
 * layer entirely.
 *
 * All three processes are shown separately because which one is down tells a
 * player whether to wait or to report it.
 */
const props = withDefaults(defineProps<ServerStatusProps>(), {
    heading: 'The gates',
    detailed: false,
})

const status = useServerStatusData()
</script>

<template>
    <section class="mx-auto max-w-6xl px-4 py-14" aria-labelledby="fantasy-status">
        <header class="mb-7 text-center">
            <h2 id="fantasy-status" class="text-xl font-semibold tracking-[0.1em] uppercase">
                {{ props.heading }}
            </h2>
            <hr class="theme-rule mx-auto mt-4 max-w-xs" />
        </header>

        <StateBlock
            v-if="status.state.error"
            variant="error"
            title="Status unavailable"
            :description="status.state.error"
        />
        <StateBlock
            v-else-if="status.state.loading && status.state.empty"
            variant="loading"
            title="Consulting the watchtower…"
        />

        <template v-else>
            <div class="grid gap-3 lg:grid-cols-[1fr_auto]">
                <!-- The three processes, as struck plates. -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div
                        v-for="process in status.processes"
                        :key="process.label"
                        class="panel theme-framed px-4 py-5 text-center"
                    >
                        <p
                            class="text-[0.64rem] tracking-[0.18em] text-[var(--text-muted)] uppercase"
                        >
                            {{ process.label }}
                        </p>
                        <p
                            class="mt-2 font-[family-name:var(--font-display)] text-base font-bold tracking-[0.12em] uppercase"
                            :class="
                                process.up ? 'text-[var(--color-up)]' : 'text-[var(--color-down)]'
                            "
                        >
                            {{ process.up ? 'Online' : 'Offline' }}
                        </p>
                    </div>
                </div>

                <!-- The headline figure, given its own plate. -->
                <div
                    class="panel theme-framed flex min-w-56 flex-col items-center justify-center px-6 py-5"
                >
                    <p class="text-[0.64rem] tracking-[0.18em] text-[var(--text-muted)] uppercase">
                        Adventurers online
                    </p>
                    <p
                        class="tabular mt-1 font-[family-name:var(--font-display)] text-4xl font-bold text-[var(--color-accent-300)]"
                    >
                        {{ status.playersOnline.toLocaleString() }}
                    </p>
                    <p
                        v-if="status.playersPeak !== null"
                        class="tabular mt-1 text-[0.7rem] text-[var(--text-muted)]"
                    >
                        Greatest muster {{ status.playersPeak.toLocaleString() }}
                    </p>
                </div>
            </div>

            <p
                v-if="status.woeActive"
                class="mt-4 text-center text-[0.72rem] font-semibold tracking-[0.14em] text-[var(--color-warn)] uppercase"
            >
                A siege is under way
            </p>

            <!-- Every configured world, when the composition asks for detail. -->
            <div v-if="props.detailed" class="mt-4 grid gap-3 md:grid-cols-2">
                <article
                    v-for="server in status.groups.flatMap((group) => group.servers)"
                    :key="server.key"
                    class="panel flex items-center justify-between gap-3 p-4"
                >
                    <div>
                        <h3 class="text-sm tracking-wide">{{ server.name }}</h3>
                        <p class="tabular mt-0.5 text-[0.8125rem] text-[var(--text-secondary)]">
                            {{ server.players_online.toLocaleString() }} online
                        </p>
                    </div>

                    <span
                        class="rounded-[var(--radius-panel)] border px-2 py-0.5 text-[0.62rem] font-semibold tracking-[0.12em] uppercase"
                        :class="
                            server.playable
                                ? 'border-[var(--color-up)]/45 text-[var(--color-up)]'
                                : 'border-[var(--color-down)]/45 text-[var(--color-down)]'
                        "
                    >
                        {{ server.playable ? 'Open' : 'Closed' }}
                    </span>
                </article>
            </div>

            <p
                v-if="status.measuredAt"
                class="theme-live mt-5 inline-block text-[0.7rem] text-[var(--text-muted)]"
            >
                Measured {{ new Date(status.measuredAt).toLocaleTimeString() }}
            </p>
        </template>
    </section>
</template>
