<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import DataTable, { type Column } from '../components/ui/DataTable.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import { api } from '../services/api'
import type { RankingEntry } from '../types/api'

/**
 * The public ladders.
 *
 * Characters belonging to banned or staff accounts are excluded by the server,
 * so nothing here filters them: the client must not be the thing deciding who
 * appears.
 */
const route = useRoute()

const entries = ref<RankingEntry[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const ladder = computed(() => (route.params.ladder === 'zeny' ? 'zeny' : 'level'))

const columns = computed<Column[]>(() => [
    { key: 'rank', label: '#', numeric: true },
    { key: 'name', label: 'Character' },
    { key: 'job', label: 'Job', secondary: true },
    ...(ladder.value === 'zeny'
        ? [{ key: 'zeny', label: 'Zeny', numeric: true }]
        : [{ key: 'level', label: 'Level', numeric: true }]),
    { key: 'guild', label: 'Guild', secondary: true },
])

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<{ data: RankingEntry[] }>(`rankings/${ladder.value}`)
        entries.value = response.data
    } catch {
        error.value = 'The ranking could not be loaded.'
    } finally {
        loading.value = false
    }
}

watch(ladder, load, { immediate: true })
</script>

<template>
    <div>
        <PageHeader title="Rankings" description="Top characters on this server.">
            <template #actions>
                <nav aria-label="Ladder" class="flex gap-1">
                    <AppButton
                        size="sm"
                        :variant="ladder === 'level' ? 'primary' : 'secondary'"
                        to="/rankings/level"
                    >
                        Level
                    </AppButton>
                    <AppButton
                        size="sm"
                        :variant="ladder === 'zeny' ? 'primary' : 'secondary'"
                        to="/rankings/zeny"
                    >
                        Zeny
                    </AppButton>
                </nav>
            </template>
        </PageHeader>

        <DataTable
            :columns="columns"
            :rows="entries"
            :row-key="(entry: RankingEntry) => entry.character.id"
            :loading="loading"
            :error="error"
            empty-title="No characters ranked yet"
            empty-description="Characters appear here once they have been created and played."
            :caption="`Top characters by ${ladder}`"
        >
            <template #retry><AppButton size="sm" @click="load">Try again</AppButton></template>

            <template #[`cell:rank`]="{ row }">
                <span class="text-[var(--text-muted)]">{{ row.rank }}</span>
            </template>

            <template #[`cell:name`]="{ row }">
                <span class="font-medium">{{ row.character.name }}</span>
            </template>

            <template #[`cell:job`]="{ row }">
                <span class="text-[var(--text-secondary)]">{{ row.character.job_name }}</span>
            </template>

            <template #[`cell:level`]="{ row }">
                {{ row.character.base_level }}
                <span class="text-[var(--text-muted)]">/ {{ row.character.job_level }}</span>
            </template>

            <template #[`cell:zeny`]="{ row }">
                {{ row.character.zeny.toLocaleString() }}
            </template>

            <template #[`cell:guild`]="{ row }">
                <span v-if="row.guild" class="text-[var(--text-secondary)]">{{ row.guild.name }}</span>
                <span v-else class="text-[var(--text-muted)]">—</span>
            </template>
        </DataTable>
    </div>
</template>
