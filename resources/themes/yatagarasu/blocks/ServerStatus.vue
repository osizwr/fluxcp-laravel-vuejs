<script setup lang="ts">
import { computed } from 'vue'
import StateBlock from '@/components/ui/StateBlock.vue'
import { useServerStatusData } from '@/blocks/data'
import type { ServerStatusProps } from '@/blocks/contracts'
import SectionHeading from '../components/SectionHeading.vue'

/**
 * Yatagarasu — the gates.
 *
 * The three emulator processes and the population, as one bordered band
 * divided by hairlines.
 *
 * Every figure is measured by the backend: reachability from a real TCP
 * connection to each process, the count from rAthena's own `char.online`
 * column. The block reads the store the broadcast feeds, so it is live
 * without knowing a socket exists — and it cannot fetch anything itself.
 *
 * All three processes are shown separately rather than reduced to one light,
 * because which one is down is the difference between "wait" and "report it".
 */
const props = withDefaults(defineProps<ServerStatusProps>(), {
    heading: 'The Gates',
    detailed: false,
})

const status = useServerStatusData()

/**
 * The measurement time in the reader's own locale. The composable passes the
 * timestamp through as the API sent it, which is an ISO string — correct to
 * transmit and not something to print.
 */
const measured = computed(() => {
    if (status.value.measuredAt === null) {
        return null
    }

    const parsed = new Date(status.value.measuredAt)

    return Number.isNaN(parsed.valueOf())
        ? null
        : parsed.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
})
</script>

<template>
    <section class="relative z-10 mx-auto max-w-7xl px-6 py-20" aria-labelledby="yata-gates-title">
        <SectionHeading
            eyebrow="Server status"
            :title="props.heading"
            title-id="yata-gates-title"
            lead="The realm awaits. Live from the game server."
            class="mb-12"
        />

        <StateBlock
            v-if="status.state.error"
            variant="error"
            title="The gates cannot be read"
            :description="status.state.error"
        />
        <StateBlock
            v-else-if="status.state.loading && status.state.empty"
            variant="loading"
            title="Approaching the gates…"
        />

        <template v-else>
            <div class="yata-gates">
                <div
                    v-for="process in status.processes"
                    :key="process.label"
                    class="yata-gates__cell"
                >
                    <p class="yata-gates__label">{{ process.label }}</p>
                    <p class="yata-gates__state" :class="process.up ? 'is-up' : 'is-down'">
                        <span
                            class="yata-nav__dot"
                            :class="process.up ? 'is-up' : 'is-down'"
                            aria-hidden="true"
                        />
                        {{ process.up ? 'Online' : 'Offline' }}
                    </p>
                </div>

                <div class="yata-gates__cell">
                    <p class="yata-gates__label">Adventurers</p>
                    <p class="yata-gates__count">
                        {{ status.playersOnline.toLocaleString() }}
                    </p>
                    <p v-if="status.playersPeak !== null" class="yata-gates__note">
                        Greatest muster {{ status.playersPeak.toLocaleString() }}
                    </p>
                </div>
            </div>

            <!-- Every configured world, when the composition asks for detail. -->
            <ul v-if="props.detailed && status.groups.length > 0" class="yata-worlds">
                <li v-for="group in status.groups" :key="group.key" class="yata-worlds__row">
                    <div class="min-w-0">
                        <p class="yata-worlds__name">{{ group.name }}</p>
                        <p class="yata-worlds__meta">
                            {{ group.login_server_up ? 'Accepting adventurers' : 'Closed' }}
                            <template v-if="status.woeActive">
                                &middot; War of Emperium in session
                            </template>
                        </p>
                    </div>

                    <p class="yata-worlds__count">
                        {{ group.players_online.toLocaleString() }}
                        <span>online</span>
                    </p>
                </li>
            </ul>

            <p v-if="measured" class="yata-gates__stamp">Measured at {{ measured }}</p>
        </template>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-gates {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-panel);
    overflow: hidden;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-gates {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

:root[data-theme-slug='yatagarasu'] .yata-gates__cell {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 8rem;
    padding: 1.5rem 1rem;
    text-align: center;
    border-right: 1px solid var(--border-subtle);
    border-bottom: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-gates__cell:nth-child(2n) {
    border-right: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-gates__cell:nth-last-child(-n + 2) {
    border-bottom: 0;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-gates__cell {
        border-bottom: 0;
        border-right: 1px solid var(--border-subtle);
    }

    :root[data-theme-slug='yatagarasu'] .yata-gates__cell:last-child {
        border-right: 0;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-gates__label {
    font-family: var(--font-display);
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-gates__state {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-family: var(--font-display);
    font-size: 1.125rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

:root[data-theme-slug='yatagarasu'] .yata-gates__state.is-up {
    color: var(--color-up);
}

:root[data-theme-slug='yatagarasu'] .yata-gates__state.is-down {
    color: var(--color-down);
}

:root[data-theme-slug='yatagarasu'] .yata-gates__count {
    font-family: var(--yata-font-deco);
    font-size: 1.875rem;
    font-weight: 700;
    line-height: 1;
    color: var(--color-accent-500);
    font-variant-numeric: tabular-nums;
}

:root[data-theme-slug='yatagarasu'] .yata-gates__note {
    font-family: var(--yata-font-tech);
    font-size: 0.65rem;
    letter-spacing: 0.08em;
    color: var(--text-muted);
}

/* ---- Worlds ------------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-worlds {
    margin: 1rem 0 0;
    padding: 0;
    list-style: none;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-panel);
}

:root[data-theme-slug='yatagarasu'] .yata-worlds__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.5rem;
}

:root[data-theme-slug='yatagarasu'] .yata-worlds__row + .yata-worlds__row {
    border-top: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-worlds__name {
    font-family: var(--font-display);
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-primary);
}

:root[data-theme-slug='yatagarasu'] .yata-worlds__meta {
    margin-top: 0.2rem;
    font-family: var(--yata-font-body);
    font-style: italic;
    font-size: 0.875rem;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-worlds__count {
    font-family: var(--yata-font-deco);
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-accent-500);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

:root[data-theme-slug='yatagarasu'] .yata-worlds__count span {
    margin-left: 0.4rem;
    font-family: var(--font-display);
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-gates__stamp {
    margin-top: 1rem;
    text-align: center;
    font-family: var(--yata-font-tech);
    font-size: 0.7rem;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--text-muted);
}
</style>
