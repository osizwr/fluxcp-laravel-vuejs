<script setup lang="ts">
import { RouterLink } from 'vue-router'
import StateBlock from '@/components/ui/StateBlock.vue'
import { useRankingData } from '@/blocks/data'
import type { RankingProps } from '@/blocks/contracts'
import CrowMark from '../components/CrowMark.vue'
import SectionHeading from '../components/SectionHeading.vue'

/**
 * Yatagarasu — the hall of legends.
 *
 * The level ladder, read from live character data. The first three places are
 * gold, silver and bronze and everything below is ordinary — three metals and
 * then nothing is what makes the top of the list read as a podium rather than
 * as the first few rows of a table.
 *
 * Ordered by the server, not here: the composable returns the ladder already
 * ranked, and a block that re-sorted would be inventing an order the rankings
 * page does not share.
 */
const props = withDefaults(defineProps<RankingProps>(), {
    heading: 'Hall of Legends',
    ladder: 'level',
    limit: 5,
    showAll: true,
})

const ranking = useRankingData(props.ladder, props.limit)

/** The three metals, then nothing. */
function rankClass(rank: number): string {
    return rank <= 3 ? `yata-rank yata-rank--${rank}` : 'yata-rank'
}
</script>

<template>
    <section aria-labelledby="yata-hall-title">
        <div class="mx-auto max-w-5xl px-4 py-16 sm:py-20">
            <SectionHeading
                eyebrow="Rankings"
                :title="props.heading"
                title-id="yata-hall-title"
                lead="Those who have climbed furthest."
            />

            <div class="mt-12">
                <StateBlock
                    v-if="ranking.state.error"
                    variant="error"
                    title="The hall is sealed"
                    :description="ranking.state.error"
                />
                <StateBlock
                    v-else-if="ranking.state.loading && ranking.state.empty"
                    variant="loading"
                    title="Reading the rolls…"
                />
                <StateBlock
                    v-else-if="ranking.state.empty"
                    variant="empty"
                    title="No legends yet"
                    description="The hall fills as adventurers make their names."
                />

                <ol v-else class="yata-plate yata-hall">
                    <li
                        v-for="entry in ranking.entries"
                        :key="entry.character.id"
                        class="yata-hall__row"
                        :class="{ 'is-first': entry.rank === 1 }"
                    >
                        <span :class="rankClass(entry.rank)" aria-hidden="true">
                            <span>{{ entry.rank }}</span>
                        </span>

                        <div class="min-w-0">
                            <p class="yata-hall__name">
                                <span class="sr-only">Rank {{ entry.rank }}:</span>
                                {{ entry.character.name }}
                            </p>
                            <p class="yata-hall__meta">
                                {{ entry.character.job_name }}
                                <template v-if="entry.guild?.name">
                                    <span class="yata-hall__sep" aria-hidden="true">&#9670;</span>
                                    {{ entry.guild.name }}
                                </template>
                            </p>
                        </div>

                        <p class="yata-hall__level tabular">
                            <span class="yata-hall__level-no">{{ entry.character.base_level }}</span>
                            <span class="yata-hall__level-sub">
                                Job {{ entry.character.job_level }}
                            </span>
                        </p>
                    </li>
                </ol>

                <p v-if="props.showAll && !ranking.state.empty" class="mt-8 text-center">
                    <RouterLink :to="`/rankings/${props.ladder}`" class="yata-btn yata-btn--secondary">
                        View full rankings
                    </RouterLink>
                </p>
            </div>
        </div>

        <!-- The crow, as a mark under the hall rather than a decoration in it. -->
        <p class="flex justify-center pb-4" aria-hidden="true">
            <CrowMark :size="20" class="text-[var(--color-accent-700)]" />
        </p>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-hall {
    margin: 0;
    padding: 0.5rem 0;
    list-style: none;
    counter-reset: none;
}

:root[data-theme-slug='yatagarasu'] .yata-hall__row {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 1.125rem;
    padding: 1rem 1.5rem;
}

:root[data-theme-slug='yatagarasu'] .yata-hall__row + .yata-hall__row {
    border-top: 1px solid var(--border-subtle);
}

/*
 * First place gets a wash and a gold edge down its leading side. It is the
 * only row with a background, which is what makes it the top of the hall
 * rather than simply the first line.
 */
:root[data-theme-slug='yatagarasu'] .yata-hall__row.is-first {
    background: linear-gradient(
        90deg,
        color-mix(in oklab, var(--yata-gold) 9%, transparent),
        transparent 55%
    );
    box-shadow: inset 2px 0 0 0 var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-hall__name {
    font-family: var(--font-display);
    font-size: 1.0625rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    color: var(--yata-ivory);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

:root[data-theme-slug='yatagarasu'] .yata-hall__row.is-first .yata-hall__name {
    color: var(--color-accent-300);
}

:root[data-theme-slug='yatagarasu'] .yata-hall__meta {
    margin-top: 0.2rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

:root[data-theme-slug='yatagarasu'] .yata-hall__sep {
    color: var(--color-accent-700);
    margin-inline: 0.4rem;
    font-size: 0.5rem;
    vertical-align: middle;
}

:root[data-theme-slug='yatagarasu'] .yata-hall__level {
    text-align: right;
    white-space: nowrap;
}

:root[data-theme-slug='yatagarasu'] .yata-hall__level-no {
    display: block;
    font-family: var(--font-display);
    font-size: 1.375rem;
    font-weight: 700;
    line-height: 1;
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-hall__level-sub {
    display: block;
    margin-top: 0.2rem;
    font-size: 0.5625rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--text-muted);
}

@media (max-width: 639px) {
    :root[data-theme-slug='yatagarasu'] .yata-hall__row {
        padding: 0.875rem 1rem;
        gap: 0.875rem;
    }

    :root[data-theme-slug='yatagarasu'] .yata-hall__level-no {
        font-size: 1.125rem;
    }
}
</style>
