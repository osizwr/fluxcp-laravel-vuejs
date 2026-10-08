<script setup lang="ts">
import { computed } from 'vue'
import StateBlock from '../components/ui/StateBlock.vue'
import StatTile from '../components/ui/StatTile.vue'
import { useStatisticsData } from './data'
import type { StatisticsProps } from './contracts'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * Aggregate counts over the game database.
 *
 * Four figures, because four is what the backend measures. Server uptime is
 * absent: rAthena records no start time the panel can read, so the figure
 * would have to be invented.
 */
const props = defineProps<StatisticsProps>()

const statistics = useStatisticsData()

/* The default lives here rather than in defineProps, which is hoisted out
 * of setup() and so cannot call t(). */
const headingText = computed(() => props.heading ?? t('stats.heading'))
</script>

<template>
    <section class="mx-auto max-w-6xl px-4 py-10" aria-labelledby="block-statistics">
        <h2 id="block-statistics" class="mb-4 text-lg font-semibold">{{ headingText }}</h2>

        <StateBlock
            v-if="statistics.state.error"
            variant="error"
            :title="t('stats.unavailable')"
            :description="statistics.state.error"
        />
        <StateBlock
            v-else-if="statistics.state.loading"
            variant="loading"
            :title="t('common.counting')"
        />

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile
                v-for="item in statistics.items"
                :key="item.key"
                :label="item.label"
                :value="item.formatted"
            />
        </div>
    </section>
</template>
