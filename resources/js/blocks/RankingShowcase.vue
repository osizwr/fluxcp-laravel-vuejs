<script setup lang="ts">
import AppButton from '../components/ui/AppButton.vue'
import StateBlock from '../components/ui/StateBlock.vue'
import { useRankingData } from './data'
import type { RankingProps } from './contracts'

/**
 * A short ladder, with a link through to the full one.
 *
 * The backend decides who appears: banned accounts, staff characters and
 * characters queued for deletion are excluded there, not here. This block
 * controls presentation only.
 */
const props = withDefaults(defineProps<RankingProps>(), {
    ladder: 'level',
    limit: 5,
    showAll: true,
})

const ranking = useRankingData(props.ladder, props.limit)

const heading = props.heading ?? (props.ladder === 'zeny' ? 'Wealthiest' : 'Top adventurers')
</script>

<template>
    <section class="mx-auto max-w-6xl px-4 py-10" aria-labelledby="block-ranking">
        <div class="mb-4 flex items-end justify-between gap-3">
            <h2 id="block-ranking" class="text-lg font-semibold">{{ heading }}</h2>
            <AppButton v-if="props.showAll" size="sm" :to="`/rankings/${props.ladder}`">
                View all
            </AppButton>
        </div>

        <StateBlock
            v-if="ranking.state.error"
            variant="error"
            title="Ranking unavailable"
            :description="ranking.state.error"
        />
        <StateBlock v-else-if="ranking.state.loading" variant="loading" title="Loading…" />
        <StateBlock
            v-else-if="ranking.state.empty"
            variant="empty"
            title="No characters ranked yet"
            description="Characters appear here once they have been created and played."
        />

        <ol v-else class="panel divide-y divide-[var(--border-subtle)]">
            <li
                v-for="entry in ranking.entries"
                :key="entry.character.id"
                class="flex items-center gap-3 px-3.5 py-2.5"
            >
                <span class="tabular w-7 text-sm text-[var(--text-muted)]">{{ entry.rank }}</span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ entry.character.name }}</span>
                    <span class="block truncate text-[0.8125rem] text-[var(--text-muted)]">
                        {{ entry.character.job_name }}
                        <template v-if="entry.guild"> · {{ entry.guild.name }}</template>
                    </span>
                </span>

                <span class="tabular text-right text-sm">
                    <template v-if="props.ladder === 'zeny'">
                        {{ entry.character.zeny.toLocaleString() }}
                    </template>
                    <template v-else>
                        {{ entry.character.base_level }}
                        <span class="text-[var(--text-muted)]">/ {{ entry.character.job_level }}</span>
                    </template>
                </span>
            </li>
        </ol>
    </section>
</template>
