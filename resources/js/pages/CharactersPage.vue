<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppButton from '../components/ui/AppButton.vue'
import DataTable, { type Column } from '../components/ui/DataTable.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { api } from '../services/api'
import type { Character } from '../types/api'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

const characters = ref<Character[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const columns: Column[] = [
    { key: 'slot', label: t('characters.slot'), numeric: true },
    { key: 'name', label: t('common.name') },
    { key: 'job', label: 'Job', secondary: true },
    { key: 'level', label: t('common.level'), numeric: true },
    { key: 'zeny', label: t('common.zeny'), numeric: true, secondary: true },
    { key: 'status', label: t('common.status') },
]

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<{ data: Character[] }>('characters/mine')
        characters.value = response.data
    } catch {
        error.value = t('characters.error')
    } finally {
        loading.value = false
    }
}

onMounted(load)
</script>

<template>
    <div>
        <PageHeader :title="t('characters.title')" description="Characters on this account." />

        <DataTable
            :columns="columns"
            :rows="characters"
            :row-key="(character: Character) => character.id"
            :loading="loading"
            :error="error"
            empty-:title="t('characters.empty')"
            empty-description="Characters you create in the game will appear here."
            caption="Characters on this account"
        >
            <template #retry
                ><AppButton size="sm" @click="load">{{ t('common.retry') }}</AppButton></template
            >

            <template #[`cell:slot`]="{ row }">
                <span class="text-[var(--text-muted)]">{{ row.slot + 1 }}</span>
            </template>

            <template #[`cell:name`]="{ row }">
                <RouterLink
                    :to="`/characters/${row.id}`"
                    class="font-medium underline underline-offset-2"
                >
                    {{ row.name }}
                </RouterLink>
                <span
                    v-if="row.guild?.name"
                    class="block text-[0.8125rem] text-[var(--text-muted)]"
                >
                    {{ row.guild.name }}
                </span>
            </template>

            <template #[`cell:job`]="{ row }">
                <span class="text-[var(--text-secondary)]">{{ row.job_name }}</span>
            </template>

            <template #[`cell:level`]="{ row }">
                {{ row.base_level }}
                <span class="text-[var(--text-muted)]">/ {{ row.job_level }}</span>
            </template>

            <template #[`cell:zeny`]="{ row }">{{ row.zeny.toLocaleString() }}</template>

            <template #[`cell:status`]="{ row }">
                <!--
                    A character queued for deletion is still in the table until
                    rAthena finalises it, so the pending state is shown rather
                    than the row being hidden.
                -->
                <StatusPill
                    v-if="row.pending_deletion"
                    state="warn"
                    :label="t('characters.deleting')"
                />
                <StatusPill
                    v-else
                    :state="row.online ? 'up' : 'down'"
                    :label="row.online ? 'Online' : 'Offline'"
                />
            </template>
        </DataTable>
    </div>
</template>
