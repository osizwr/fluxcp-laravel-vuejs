<script setup lang="ts">
import { computed } from 'vue'
import StateBlock from '@/components/ui/StateBlock.vue'
import { useClassShowcaseData } from '@/blocks/data'
import type { ClassShowcaseProps } from '@/blocks/contracts'
import ArtPlaceholder from '../components/ArtPlaceholder.vue'
import SectionHeading from '../components/SectionHeading.vue'

/**
 * Yatagarasu — choose your path, and who walks the realm.
 *
 * One section doing two jobs, because they are the same data seen twice: the
 * classes people actually play, as portraits to choose from and as a reading
 * of the population.
 *
 * Everything is measured. The classes listed are the ones characters on this
 * server have, ordered by how many, from a real GROUP BY over the character
 * table — not a fixed list of the six original jobs. A server running a
 * custom class list shows its own, and a brand new server shows nothing here
 * rather than six invented bars.
 */
const props = withDefaults(defineProps<ClassShowcaseProps>(), {
    heading: 'Choose Your Path',
    limit: 6,
})

const showcase = useClassShowcaseData(props.limit)

/**
 * Portrait slots, keyed by the job name the server reports.
 *
 * Lower-cased and stripped to letters so `High Priest` and `high-priest`
 * resolve to the same file. A class with no portrait falls back to the
 * placeholder, which is the common case on a server with custom jobs.
 */
function portraitFor(jobName: string): string {
    const slug = jobName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')

    return `/images/classes/${slug}.webp`
}

/** Share of the busiest class, for the bar widths. */
function share(count: number): number {
    return showcase.value.busiest === 0
        ? 0
        : Math.round((count / showcase.value.busiest) * 100)
}

const total = computed(() =>
    showcase.value.classes.reduce((sum, entry) => sum + entry.characters, 0),
)
</script>

<template>
    <section aria-labelledby="yata-classes-title">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:py-20">
            <SectionHeading
                eyebrow="Paths"
                :title="props.heading"
                title-id="yata-classes-title"
                lead="Every road through the realm begins with a choice."
            />

            <div class="mt-12">
                <StateBlock
                    v-if="showcase.state.error"
                    variant="error"
                    title="The rolls cannot be read"
                    :description="showcase.state.error"
                />
                <StateBlock
                    v-else-if="showcase.state.loading && showcase.state.empty"
                    variant="loading"
                    title="Counting the realm…"
                />
                <StateBlock
                    v-else-if="showcase.state.empty"
                    variant="empty"
                    title="No paths walked yet"
                    description="Classes appear here once adventurers have taken them."
                />

                <template v-else>
                    <!-- The portraits. -->
                    <ul class="yata-paths">
                        <li
                            v-for="entry in showcase.classes"
                            :key="entry.job_id"
                            class="yata-path"
                        >
                            <div class="yata-path__art">
                                <ArtPlaceholder
                                    :path="portraitFor(entry.job_name)"
                                    :alt="`A ${entry.job_name} of the realm`"
                                    ratio="aspect-[3/4]"
                                />
                                <div class="yata-path__fade" aria-hidden="true" />
                            </div>

                            <div class="yata-path__plate">
                                <h3 class="yata-path__name">{{ entry.job_name }}</h3>
                                <p class="yata-path__count tabular">
                                    {{ entry.characters.toLocaleString() }}
                                    <span>walking</span>
                                </p>
                            </div>
                        </li>
                    </ul>

                    <!-- The same figures, read as a population. -->
                    <div class="yata-plate yata-roll">
                        <div class="yata-roll__head">
                            <h3 class="yata-roll__title">Who walks the realm</h3>
                            <p class="yata-roll__total tabular">
                                {{ total.toLocaleString() }}
                                <span>characters counted</span>
                            </p>
                        </div>

                        <ul class="yata-roll__list">
                            <li
                                v-for="entry in showcase.classes"
                                :key="entry.job_id"
                                class="yata-roll__row"
                            >
                                <p class="yata-roll__label">{{ entry.job_name }}</p>

                                <div
                                    class="yata-meter"
                                    role="img"
                                    :aria-label="`${entry.job_name}: ${entry.characters} characters`"
                                >
                                    <div class="yata-meter__fill" :style="{ width: `${share(entry.characters)}%` }" />
                                </div>

                                <p class="yata-roll__value tabular">
                                    {{ entry.characters.toLocaleString() }}
                                </p>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>
        </div>
    </section>
</template>

<style>
/* ---- Portraits --------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-paths {
    display: grid;
    gap: 0.875rem;
    margin: 0;
    padding: 0;
    list-style: none;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-paths {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (min-width: 1100px) {
    :root[data-theme-slug='yatagarasu'] .yata-paths {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
}

:root[data-theme-slug='yatagarasu'] .yata-path {
    position: relative;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-panel);
    overflow: hidden;
    background-color: var(--surface-raised);
    transition: border-color 220ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-path:hover {
    border-color: color-mix(in oklab, var(--yata-gold) 45%, var(--border-subtle));
}

:root[data-theme-slug='yatagarasu'] .yata-path__art {
    position: relative;
    overflow: hidden;
}

:root[data-theme-slug='yatagarasu'] .yata-path__art figure {
    border: 0;
    transition: scale 420ms var(--yata-ease);
}

/* The portrait leans in slightly. 1.04, not 1.15 — this is a breath, not a zoom. */
:root[data-theme-slug='yatagarasu'] .yata-path:hover .yata-path__art figure {
    scale: 1.04;
}

:root[data-theme-slug='yatagarasu'] .yata-path__fade {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        180deg,
        transparent 45%,
        color-mix(in oklab, var(--yata-void) 90%, transparent) 100%
    );
}

:root[data-theme-slug='yatagarasu'] .yata-path__plate {
    position: relative;
    padding: 0.875rem 0.875rem 1rem;
    text-align: center;
    /* Sits over the foot of the portrait rather than below it. */
    margin-top: -3.25rem;
}

:root[data-theme-slug='yatagarasu'] .yata-path__name {
    font-family: var(--font-display);
    font-size: 0.875rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-path__count {
    margin-top: 0.3rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-accent-400);
}

:root[data-theme-slug='yatagarasu'] .yata-path__count span {
    display: block;
    font-size: 0.5625rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--text-muted);
}

/* ---- The roll ---------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-roll {
    margin-top: 1rem;
    padding: 1.75rem;
}

:root[data-theme-slug='yatagarasu'] .yata-roll__head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.75rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-roll__title {
    font-family: var(--font-display);
    font-size: 1.0625rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-roll__total {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-accent-400);
}

:root[data-theme-slug='yatagarasu'] .yata-roll__total span {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-left: 0.4rem;
}

:root[data-theme-slug='yatagarasu'] .yata-roll__list {
    margin: 0;
    padding: 0;
    list-style: none;
}

/*
 * Label, bar, figure. The label column is fixed so the bars all start at the
 * same x — a chart whose bars begin in different places is not a chart.
 */
:root[data-theme-slug='yatagarasu'] .yata-roll__row {
    display: grid;
    grid-template-columns: 7.5rem 1fr 3.5rem;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 0;
}

:root[data-theme-slug='yatagarasu'] .yata-roll__row + .yata-roll__row {
    border-top: 1px solid color-mix(in oklab, var(--border-subtle) 60%, transparent);
}

:root[data-theme-slug='yatagarasu'] .yata-roll__label {
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

:root[data-theme-slug='yatagarasu'] .yata-roll__value {
    font-size: 0.8125rem;
    font-weight: 600;
    text-align: right;
    color: var(--yata-ivory);
}

@media (max-width: 639px) {
    :root[data-theme-slug='yatagarasu'] .yata-roll {
        padding: 1.25rem;
    }

    /* The label moves above its bar rather than squeezing to three letters. */
    :root[data-theme-slug='yatagarasu'] .yata-roll__row {
        grid-template-columns: 1fr auto;
        gap: 0.4rem 0.75rem;
    }

    :root[data-theme-slug='yatagarasu'] .yata-roll__label {
        grid-column: 1;
    }

    :root[data-theme-slug='yatagarasu'] .yata-roll__value {
        grid-column: 2;
        grid-row: 1;
    }

    :root[data-theme-slug='yatagarasu'] .yata-roll__row .yata-meter {
        grid-column: 1 / -1;
    }
}

@media (prefers-reduced-motion: reduce) {
    :root[data-theme-slug='yatagarasu'] .yata-path__art figure,
    :root[data-theme-slug='yatagarasu'] .yata-path:hover .yata-path__art figure {
        transition: none;
        scale: 1;
    }
}
</style>
