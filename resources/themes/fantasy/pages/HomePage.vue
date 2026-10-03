<script setup lang="ts">
import { computed } from 'vue'
import AlertMessage from '@/components/ui/AlertMessage.vue'
import AppButton from '@/components/ui/AppButton.vue'
import StateBlock from '@/components/ui/StateBlock.vue'
import { useGame } from '@/composables/useGame'
import { useServerStore } from '@/stores/server'

/**
 * Fantasy — the front page.
 *
 * Overrides the core HomePage. Every figure here is measured by the backend:
 * per-process reachability from a real TCP connection, player counts from
 * rAthena's own `char.online` column. Nothing is invented to fill the layout
 * out, which is why there are three figures rather than the eight a dashboard
 * mockup would have.
 *
 * Realtime comes from the existing store, which is fed by the Reverb broadcast
 * with a polling fallback. This page only displays it; there is no second
 * websocket connection here.
 */
const servers = useServerStore()
const { game, title } = useGame()

const peak = computed(() => {
    const peaks = servers.groups
        .flatMap((group) => group.servers)
        .map((server) => server.players_peak)
        .filter((value): value is number => value !== null)

    return peaks.length > 0 ? Math.max(...peaks) : null
})

const measuredAt = computed(() =>
    servers.measuredAt === null ? null : new Date(servers.measuredAt).toLocaleTimeString(),
)

/** The three emulator processes, in the order a player passes through them. */
function processes(server: {
    login_server_up: boolean
    char_server_up: boolean
    map_server_up: boolean
}): Array<{ label: string; up: boolean }> {
    return [
        { label: 'Login', up: server.login_server_up },
        { label: 'Character', up: server.char_server_up },
        { label: 'Map', up: server.map_server_up },
    ]
}
</script>

<template>
    <div>
        <!-- Masthead. An inscription, not a marketing hero. -->
        <header class="mb-7 text-center">
            <h1 class="text-2xl font-semibold tracking-[0.06em] uppercase sm:text-3xl">
                {{ title }}
            </h1>
            <p
                v-if="game.description"
                class="mt-1.5 text-[0.72rem] tracking-[0.2em] text-[var(--text-muted)] uppercase"
            >
                {{ game.description }}
            </p>
            <hr class="theme-rule mx-auto mt-5 max-w-md" />
        </header>

        <AlertMessage v-if="servers.woeInProgress" tone="warning" title="War of Emperium">
            A siege is under way. Some pages are closed until it ends.
        </AlertMessage>

        <AlertMessage v-if="servers.error" tone="error" title="Status unavailable" class="mt-4">
            {{ servers.error }}
        </AlertMessage>

        <StateBlock
            v-else-if="servers.loading && servers.groups.length === 0"
            variant="loading"
            title="Consulting the realm…"
        />

        <template v-else>
            <!--
                Three figures, each of which the backend actually measures. A
                fourth would have to be invented.
            -->
            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="panel theme-framed px-4 py-3.5 text-center">
                    <p class="text-[0.68rem] tracking-[0.16em] text-[var(--text-muted)] uppercase">
                        Adventurers online
                    </p>
                    <p
                        class="tabular mt-1.5 font-[family-name:var(--font-display)] text-3xl font-bold text-[var(--color-accent-300)]"
                    >
                        {{ servers.playersOnline.toLocaleString() }}
                    </p>
                    <p
                        v-if="measuredAt"
                        class="theme-live mt-1 inline-block text-[0.7rem] text-[var(--text-muted)]"
                    >
                        as of {{ measuredAt }}
                    </p>
                </div>

                <div class="panel px-4 py-3.5 text-center">
                    <p class="text-[0.68rem] tracking-[0.16em] text-[var(--text-muted)] uppercase">
                        The gates
                    </p>
                    <p
                        class="mt-1.5 font-[family-name:var(--font-display)] text-3xl font-bold"
                        :class="
                            servers.anyServerUp
                                ? 'text-[var(--color-up)]'
                                : 'text-[var(--color-down)]'
                        "
                    >
                        {{ servers.anyServerUp ? 'Open' : 'Shut' }}
                    </p>
                    <p class="mt-1 text-[0.7rem] text-[var(--text-muted)]">
                        {{ servers.anyServerUp ? 'Accepting travellers' : 'Not reachable' }}
                    </p>
                </div>

                <div v-if="peak !== null" class="panel px-4 py-3.5 text-center">
                    <p class="text-[0.68rem] tracking-[0.16em] text-[var(--text-muted)] uppercase">
                        Greatest muster
                    </p>
                    <p
                        class="tabular mt-1.5 font-[family-name:var(--font-display)] text-3xl font-bold"
                    >
                        {{ peak.toLocaleString() }}
                    </p>
                    <p class="mt-1 text-[0.7rem] text-[var(--text-muted)]">Highest recorded</p>
                </div>
            </div>

            <!-- One plate per world. -->
            <section
                v-for="group in servers.groups"
                :key="group.key"
                class="mt-7"
                :aria-labelledby="`realm-${group.key}`"
            >
                <h2
                    :id="`realm-${group.key}`"
                    class="mb-3 text-[0.72rem] tracking-[0.18em] text-[var(--text-secondary)] uppercase"
                >
                    {{ group.name }}
                </h2>

                <div class="grid gap-3 md:grid-cols-2">
                    <article
                        v-for="server in group.servers"
                        :key="server.key"
                        class="panel theme-framed p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base tracking-wide">{{ server.name }}</h3>
                                <p class="tabular mt-0.5 text-sm text-[var(--text-secondary)]">
                                    {{ server.players_online.toLocaleString() }} online
                                </p>
                            </div>

                            <span
                                class="rounded-[var(--radius-panel)] border px-2 py-0.5 text-[0.65rem] font-semibold tracking-[0.12em] uppercase"
                                :class="
                                    server.playable
                                        ? 'border-[var(--color-up)]/45 bg-[var(--status-up-bg)] text-[var(--color-up)]'
                                        : 'border-[var(--color-down)]/45 bg-[var(--status-down-bg)] text-[var(--color-down)]'
                                "
                            >
                                {{ server.playable ? 'Open' : 'Closed' }}
                            </span>
                        </div>

                        <!--
                            All three processes shown separately: which one is
                            down tells a player whether to wait or to report it.
                        -->
                        <dl
                            class="mt-4 grid grid-cols-3 gap-px overflow-hidden bg-[var(--border-subtle)]"
                        >
                            <div
                                v-for="process in processes(server)"
                                :key="process.label"
                                class="bg-[var(--surface-sunken)] px-2 py-2 text-center"
                            >
                                <dt
                                    class="text-[0.62rem] tracking-[0.12em] text-[var(--text-muted)] uppercase"
                                >
                                    {{ process.label }}
                                </dt>
                                <dd
                                    class="mt-1 text-[0.72rem] font-semibold tracking-wider uppercase"
                                    :class="
                                        process.up
                                            ? 'text-[var(--color-up)]'
                                            : 'text-[var(--color-down)]'
                                    "
                                >
                                    {{ process.up ? 'Up' : 'Down' }}
                                </dd>
                            </div>
                        </dl>

                        <p
                            v-if="server.woe_active"
                            class="mt-3 text-[0.7rem] font-semibold tracking-[0.1em] text-[var(--color-warn)] uppercase"
                        >
                            Siege in progress
                        </p>
                    </article>
                </div>
            </section>

            <!-- Only to pages that exist. -->
            <nav aria-label="Explore" class="mt-8 flex flex-wrap justify-center gap-2">
                <AppButton variant="primary" to="/rankings/level">Hall of fame</AppButton>
                <AppButton to="/who-is-online">Who walks the realm</AppButton>
            </nav>
        </template>
    </div>
</template>
