<script setup lang="ts">
import { computed } from 'vue'
import StateBlock from '@/components/ui/StateBlock.vue'
import { useServerStatusData } from '@/blocks/data'
import type { ServerStatusProps } from '@/blocks/contracts'
import SectionHeading from '../components/SectionHeading.vue'

/**
 * Yatagarasu — the gates.
 *
 * The three emulator processes and the population, as four tall panels that
 * read like terminals at a threshold.
 *
 * Every figure here is measured by the backend: reachability from a real TCP
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
 * The measurement time, in the reader's own locale.
 *
 * The composable passes the timestamp through as the API sent it, which is an
 * ISO string — correct to transmit and not something to print. An
 * unparseable value yields nothing rather than the raw string.
 */
const measured = computed(() => {
    if (status.value.measuredAt === null) {
        return null
    }

    const parsed = new Date(status.value.measuredAt)

    return Number.isNaN(parsed.valueOf())
        ? null
        : parsed.toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
          })
})
</script>

<template>
    <section class="yata-air" aria-labelledby="yata-gates-title">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:py-20">
            <SectionHeading
                eyebrow="Server status"
                :title="props.heading"
                title-id="yata-gates-title"
                lead="The realm awaits. Live from the game server."
            />

            <div class="mt-12">
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
                        <!-- One panel per emulator process. -->
                        <div
                            v-for="(process, index) in status.processes"
                            :key="process.label"
                            class="yata-plate yata-gate yata-rise"
                            :class="`yata-rise-${index + 1}`"
                        >
                            <p class="yata-gate__label">{{ process.label }}</p>
                            <p
                                class="yata-gate__value"
                                :class="process.up ? 'is-up' : 'is-down'"
                            >
                                {{ process.up ? 'Online' : 'Offline' }}
                            </p>
                            <span
                                class="yata-gate__lamp"
                                :class="process.up ? 'is-up' : 'is-down'"
                                aria-hidden="true"
                            />
                        </div>

                        <!-- And one for the population. -->
                        <div class="yata-plate yata-gate yata-rise yata-rise-4">
                            <p class="yata-gate__label">Adventurers</p>
                            <p class="yata-gate__value tabular is-count">
                                {{ status.playersOnline.toLocaleString() }}
                            </p>
                            <p v-if="status.playersPeak !== null" class="yata-gate__note tabular">
                                Greatest muster {{ status.playersPeak.toLocaleString() }}
                            </p>
                        </div>
                    </div>

                    <!--
                        Every configured world, when the composition asks for
                        detail. A server with one world shows one row, which is
                        why this is not a grid.
                    -->
                    <ul v-if="props.detailed && status.groups.length > 0" class="yata-worlds">
                        <li v-for="group in status.groups" :key="group.key" class="yata-world">
                            <div class="min-w-0">
                                <p class="yata-world__name">{{ group.name }}</p>
                                <p class="yata-world__meta">
                                    {{
                                        group.login_server_up
                                            ? 'Accepting adventurers'
                                            : 'Closed'
                                    }}
                                    <template v-if="status.woeActive">
                                        &middot; War of Emperium in session
                                    </template>
                                </p>
                            </div>

                            <p class="yata-world__count tabular">
                                {{ group.players_online.toLocaleString() }}
                                <span>online</span>
                            </p>
                        </li>
                    </ul>

                    <p v-if="measured" class="yata-gates__stamp">
                        Measured at {{ measured }}
                    </p>
                </template>
            </div>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-gates {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (min-width: 900px) {
    :root[data-theme-slug='yatagarasu'] .yata-gates {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

:root[data-theme-slug='yatagarasu'] .yata-gate {
    position: relative;
    padding: 1.75rem 1.25rem 1.5rem;
    text-align: center;
    min-height: 9.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.625rem;
}

:root[data-theme-slug='yatagarasu'] .yata-gate__label {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-gate__value {
    font-family: var(--font-display);
    font-size: 1.375rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

:root[data-theme-slug='yatagarasu'] .yata-gate__value.is-up {
    color: var(--color-up);
}

:root[data-theme-slug='yatagarasu'] .yata-gate__value.is-down {
    color: var(--color-down);
}

/* The population is a figure, not a state, so it is ivory and larger. */
:root[data-theme-slug='yatagarasu'] .yata-gate__value.is-count {
    font-size: 2rem;
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-gate__note {
    font-size: 0.6875rem;
    letter-spacing: 0.08em;
    color: var(--text-muted);
}

/*
 * A lamp along the foot of the panel: a hairline in the status colour, with
 * the glow confined to it. A lit gate and an unlit one, rather than a badge.
 */
:root[data-theme-slug='yatagarasu'] .yata-gate__lamp {
    position: absolute;
    left: 20%;
    right: 20%;
    bottom: -1px;
    height: 1px;
}

:root[data-theme-slug='yatagarasu'] .yata-gate__lamp.is-up {
    background: linear-gradient(90deg, transparent, var(--color-up), transparent);
    box-shadow: 0 0 12px color-mix(in oklab, var(--color-up) 55%, transparent);
}

:root[data-theme-slug='yatagarasu'] .yata-gate__lamp.is-down {
    background: linear-gradient(90deg, transparent, var(--color-down), transparent);
}

/* ---- Worlds ------------------------------------------------------------ */

:root[data-theme-slug='yatagarasu'] .yata-worlds {
    margin: 1rem 0 0;
    padding: 0;
    list-style: none;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-panel);
}

:root[data-theme-slug='yatagarasu'] .yata-world {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem;
}

:root[data-theme-slug='yatagarasu'] .yata-world + .yata-world {
    border-top: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-world__name {
    font-family: var(--font-display);
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-world__meta {
    margin-top: 0.2rem;
    font-size: 0.75rem;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-world__count {
    font-family: var(--font-display);
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-accent-300);
    white-space: nowrap;
}

:root[data-theme-slug='yatagarasu'] .yata-world__count span {
    font-family: 'Manrope', ui-sans-serif, system-ui, sans-serif;
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-left: 0.35rem;
}

:root[data-theme-slug='yatagarasu'] .yata-gates__stamp {
    margin-top: 1rem;
    text-align: center;
    font-size: 0.6875rem;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--text-muted);
}
</style>
