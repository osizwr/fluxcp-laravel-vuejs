<script setup lang="ts">
import StateBlock from '@/components/ui/StateBlock.vue'
import { useStatisticsData } from '@/blocks/data'
import type { StatisticsProps } from '@/blocks/contracts'
import SectionHeading from '../components/SectionHeading.vue'

/**
 * Yatagarasu — the realm in numbers.
 *
 * Four counts over the live game tables, already formatted by the composable.
 * The block chooses the arrangement and nothing else — which is why it has no
 * formatting logic of its own and no fallback figures: a statistic the server
 * cannot answer is shown as unavailable rather than as a plausible number.
 */
const props = withDefaults(defineProps<StatisticsProps>(), {
    heading: 'The Realm in Numbers',
})

const statistics = useStatisticsData()
</script>

<template>
    <section class="yata-air" aria-labelledby="yata-stats-title">
        <div class="mx-auto max-w-7xl px-4 pb-16 sm:pb-20">
            <SectionHeading
                eyebrow="A living world"
                :title="props.heading"
                title-id="yata-stats-title"
            />

            <div class="mt-12">
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

                <dl v-else class="yata-figures">
                    <div
                        v-for="(item, index) in statistics.items"
                        :key="item.key"
                        class="yata-figure yata-rise"
                        :class="`yata-rise-${Math.min(index + 1, 4)}`"
                    >
                        <dt class="yata-figure__label">{{ item.label }}</dt>
                        <dd class="yata-figure__value tabular">{{ item.formatted }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>
</template>

<style>
/*
 * A single bordered band divided by hairlines, not four separate cards. The
 * figures belong together — they are one reading of the world — and four
 * floating panels say they are four unrelated facts.
 */
:root[data-theme-slug='yatagarasu'] .yata-figures {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-panel);
    background-image: linear-gradient(
        180deg,
        color-mix(in oklab, var(--yata-navy) 70%, transparent),
        transparent 70%
    );
}

@media (min-width: 900px) {
    :root[data-theme-slug='yatagarasu'] .yata-figures {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

:root[data-theme-slug='yatagarasu'] .yata-figure {
    padding: 2rem 1.25rem;
    text-align: center;
}

/*
 * Dividers drawn per cell rather than with a gap-and-background trick, so the
 * two-column layout on a phone does not leave a stray rule down the middle of
 * the last row.
 */
:root[data-theme-slug='yatagarasu'] .yata-figure:nth-child(n + 3) {
    border-top: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-figure:nth-child(2n) {
    border-left: 1px solid var(--border-subtle);
}

@media (min-width: 900px) {
    :root[data-theme-slug='yatagarasu'] .yata-figure:nth-child(n + 3) {
        border-top: 0;
    }

    :root[data-theme-slug='yatagarasu'] .yata-figure:nth-child(2n) {
        border-left: 0;
    }

    :root[data-theme-slug='yatagarasu'] .yata-figure + .yata-figure {
        border-left: 1px solid var(--border-subtle);
    }
}

:root[data-theme-slug='yatagarasu'] .yata-figure__label {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-figure__value {
    margin: 0.75rem 0 0;
    font-family: var(--font-display);
    font-size: clamp(1.75rem, 4vw, 2.5rem);
    font-weight: 700;
    line-height: 1;
    color: var(--color-accent-300);
}
</style>
