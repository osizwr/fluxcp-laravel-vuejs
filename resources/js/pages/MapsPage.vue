<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable, { type Column } from '../components/ui/DataTable.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import { api } from '../services/api'
import { useTranslation } from '../i18n'

/**
 * Where people are playing right now.
 *
 * Characters who have asked to hide their map are left out by the server
 * entirely rather than being counted somewhere, because a count of one on an
 * unusual map locates them by elimination.
 */
interface MapRow {
    map: string
    players: number
}

const { t } = useTranslation()

const rows = ref<MapRow[]>([])
const totalOnline = ref(0)
const loading = ref(true)
const error = ref<string | null>(null)

const columns = computed<Column[]>(() => [
    { key: 'map', label: t('server.map') },
    { key: 'players', label: t('common.players'), numeric: true },
    { key: 'share', label: t('maps.share'), numeric: true, secondary: true },
])

function share(players: number): string {
    return totalOnline.value === 0 ? '—' : `${Math.round((players / totalOnline.value) * 100)}%`
}

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<{ data: MapRow[]; meta: { total_online: number } }>(
            'characters/maps',
        )

        rows.value = response.data
        totalOnline.value = response.meta.total_online
    } catch {
        error.value = t('maps.error')
        rows.value = []
    } finally {
        loading.value = false
    }
}

onMounted(load)
</script>

<template>
    <div class="py-6">
        <PageHeader
            :title="t('maps.title')"
            :description="
                totalOnline > 0
                    ? `${totalOnline.toLocaleString()} players across ${rows.length} maps.`
                    : 'Where people are playing right now.'
            "
        />

        <DataTable
            class="mt-4"
            :columns="columns"
            :rows="rows"
            :row-key="(row: MapRow) => row.map"
            :loading="loading"
            :error="error"
            caption="Players per map"
            empty-:title="t('online.empty')"
            empty-description="Nothing to show until players log in."
        >
            <template #cell:map="{ row }">{{ row.map }}</template>
            <template #cell:players="{ row }">{{ row.players.toLocaleString() }}</template>
            <template #cell:share="{ row }">{{ share(row.players) }}</template>
        </DataTable>
    </div>
</template>
