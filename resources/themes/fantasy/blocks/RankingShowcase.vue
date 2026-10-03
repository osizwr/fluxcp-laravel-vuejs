<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue'
import StateBlock from '@/components/ui/StateBlock.vue'
import { useRankingData } from '@/blocks/data'
import type { RankingProps } from '@/blocks/contracts'

/**
 * Fantasy — a short ladder.
 *
 * The top three are marked with a medal colour; the rest are numbered plainly,
 * because making every row special makes none of them special.
 *
 * Who appears is decided by the backend -- banned accounts, staff characters
 * and characters queued for deletion are excluded there. This block presents.
 */
const props = withDefaults(defineProps<RankingProps>(), {
    ladder: 'level',
    limit: 5,
    showAll: true,
})

const ranking = useRankingData(props.ladder, props.limit)

const heading = props.heading ?? (props.ladder === 'zeny' ? 'Wealthiest' : 'Hall of fame')

/** Gold, silver and bronze for the first three. */
function rankColour(rank: number): string {
    if (rank === 1) return 'text-[var(--color-accent-300)]'
    if (rank === 2) return 'text-[var(--text-secondary)]'
    if (rank === 3) return 'text-[var(--color-accent-700)]'

    return 'text-[var(--text-muted)]'
}
</script>

<template>
    <section class="mx-auto max-w-4xl px-4 py-14" aria-labelledby="fantasy-ranking">
        <header class="mb-7 text-center">
            <h2 id="fantasy-ranking" class="text-xl font-semibold tracking-[0.1em] uppercase">
                {{ heading }}
            </h2>
            <hr class="theme-rule mx-auto mt-4 max-w-xs" />
        </header>

        <StateBlock
            v-if="ranking.state.error"
            variant="error"
            title="Ranking unavailable"
            :description="ranking.state.error"
        />
        <StateBlock v-else-if="ranking.state.loading" variant="loading" title="Reading the rolls…" />
        <StateBlock
            v-else-if="ranking.state.empty"
            variant="empty"
            title="The rolls are empty"
            description="Characters appear here once they have been created and played."
        />

        <template v-else>
            <ol class="panel theme-framed divide-y divide-[var(--border-subtle)]">
                <li
                    v-for="entry in ranking.entries"
                    :key="entry.character.id"
                    class="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-[var(--surface-hover)]"
                >
                    <span
                        class="tabular w-8 font-[family-name:var(--font-display)] text-lg font-bold"
                        :class="rankColour(entry.rank)"
                    >
                        {{ entry.rank }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ entry.character.name }}</span>
                        <span
                            class="block truncate text-[0.68rem] tracking-[0.1em] text-[var(--text-muted)] uppercase"
                        >
                            {{ entry.character.job_name }}
                            <template v-if="entry.guild"> · {{ entry.guild.name }}</template>
                        </span>
                    </span>

                    <span class="tabular text-right">
                        <template v-if="props.ladder === 'zeny'">
                            <span class="font-semibold">
                                {{ entry.character.zeny.toLocaleString() }}
                            </span>
                            <span
                                class="block text-[0.62rem] tracking-[0.12em] text-[var(--text-muted)] uppercase"
                            >
                                zeny
                            </span>
                        </template>
                        <template v-else>
                            <span class="font-semibold">{{ entry.character.base_level }}</span>
                            <span
                                class="block text-[0.62rem] tracking-[0.12em] text-[var(--text-muted)] uppercase"
                            >
                                job {{ entry.character.job_level }}
                            </span>
                        </template>
                    </span>
                </li>
            </ol>

            <div v-if="props.showAll" class="mt-5 text-center">
                <AppButton :to="`/rankings/${props.ladder}`">See the full rolls</AppButton>
            </div>
        </template>
    </section>
</template>
