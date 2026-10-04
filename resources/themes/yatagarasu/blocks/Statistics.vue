<script setup lang="ts">
import StateBlock from '@/components/ui/StateBlock.vue'
import { useStatisticsData } from '@/blocks/data'
import type { StatisticsProps } from '@/blocks/contracts'

/**
 * Yatagarasu — the pulse.
 *
 * The headline counts, directly under the hero and with no section heading:
 * one bordered band divided by hairlines, read at a glance on the way down
 * the page. It is the reference's arrangement, and it works because these
 * figures need no introduction — a number under the word "Characters" is
 * self-explanatory in a way that a chart is not.
 *
 * The figures are counted over the live game tables and formatted by the
 * composable. A statistic the server cannot answer is shown as unavailable
 * rather than as a plausible number.
 */
withDefaults(defineProps<StatisticsProps>(), { heading: 'The Realm in Numbers' })

const statistics = useStatisticsData()
</script>

<template>
    <section class="relative z-10 mx-auto max-w-7xl px-6 py-12" aria-label="Server statistics">
        <StateBlock
            v-if="statistics.state.error"
            variant="error"
            title="The archives are unavailable"
            :description="statistics.state.error"
        />
        <StateBlock
            v-else-if="statistics.state.loading && statistics.state.empty"
            variant="loading"
            title="Reading the archives…"
        />

        <dl v-else class="yata-pulse">
            <div v-for="item in statistics.items" :key="item.key" class="yata-pulse__cell">
                <dd class="yata-pulse__value">{{ item.formatted }}</dd>
                <dt class="yata-pulse__label">{{ item.label }}</dt>
            </div>
        </dl>
    </section>
</template>

<style>
/*
 * One outer border with the cells divided by internal rules, rather than a row
 * of separate cards. These figures are one reading of the world; four floating
 * panels would say they are four unrelated facts.
 *
 * The dividers are drawn per cell and then trimmed with a negative margin on
 * the container, which is what keeps a part-filled last row from leaving a
 * stray rule hanging off the end.
 */
:root[data-theme-slug='yatagarasu'] .yata-pulse {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-panel);
    margin: 0;
    overflow: hidden;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-pulse {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

:root[data-theme-slug='yatagarasu'] .yata-pulse__cell {
    padding: 1.5rem;
    text-align: center;
    border-right: 1px solid var(--border-subtle);
    border-bottom: 1px solid var(--border-subtle);
}

/*
 * The trailing edges are removed per row rather than with `:last-child`, which
 * would only reach the final cell of the whole grid.
 */
:root[data-theme-slug='yatagarasu'] .yata-pulse__cell:nth-child(2n) {
    border-right: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-pulse__cell:nth-last-child(-n + 2) {
    border-bottom: 0;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-pulse__cell:nth-child(2n) {
        border-right: 1px solid var(--border-subtle);
    }

    :root[data-theme-slug='yatagarasu'] .yata-pulse__cell:nth-child(4n),
    :root[data-theme-slug='yatagarasu'] .yata-pulse__cell:last-child {
        border-right: 0;
    }

    :root[data-theme-slug='yatagarasu'] .yata-pulse__cell {
        border-bottom: 0;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-pulse__value {
    margin: 0;
    font-family: var(--yata-font-deco);
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.1;
    color: var(--color-accent-500);
    font-variant-numeric: tabular-nums;
}

:root[data-theme-slug='yatagarasu'] .yata-pulse__label {
    margin-top: 0.4rem;
    font-family: var(--font-display);
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--text-muted);
}
</style>
