<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import DataTable, { type Column } from '../components/ui/DataTable.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import { api, ApiError } from '../services/api'
import { useAuthStore } from '../stores/auth'
import type { Character, Paginated } from '../types/api'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * Who is online.
 *
 * The location column only appears for viewers the server will send it to.
 * That is not the client's decision: the API withholds the field, and this
 * reads the permission purely to avoid rendering a column of blanks.
 */
const auth = useAuthStore()

const characters = ref<Character[]>([])
const total = ref(0)
const page = ref(1)
const lastPage = ref(1)
const search = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const unavailable = ref(false)

const columns = ref<Column[]>([])

function buildColumns(): void {
    columns.value = [
        { key: 'name', label: t('common.character') },
        { key: 'job', label: 'Job', secondary: true },
        { key: 'level', label: t('common.level'), numeric: true },
        ...(auth.can('ViewOnlinePosition')
            ? [{ key: 'map', label: t('online.location'), secondary: true }]
            : []),
        { key: 'guild', label: t('common.guild'), secondary: true },
    ]
}

async function load(): Promise<void> {
    loading.value = true
    error.value = null
    unavailable.value = false

    try {
        const response = await api.get<Paginated<Character>>('characters/online', {
            page: page.value,
            name: search.value || undefined,
        })

        characters.value = response.data
        total.value = response.meta.total
        lastPage.value = response.meta.last_page
    } catch (caught) {
        // 503 is the server refusing this page during War of Emperium, which
        // is a deliberate restriction rather than a fault.
        if (caught instanceof ApiError && caught.status === 503) {
            unavailable.value = true
        } else {
            error.value = t('online.error')
        }
    } finally {
        loading.value = false
    }
}

let searchTimer: number | null = null

watch(search, () => {
    if (searchTimer !== null) {
        window.clearTimeout(searchTimer)
    }

    // Debounced so typing does not fire a request per keystroke.
    searchTimer = window.setTimeout(() => {
        page.value = 1
        void load()
    }, 300)
})

watch(() => auth.permissions, buildColumns, { deep: true })

onMounted(() => {
    buildColumns()
    void load()
})

function goToPage(next: number): void {
    page.value = Math.min(Math.max(next, 1), lastPage.value)
    void load()
}
</script>

<template>
    <div>
        <PageHeader
            :title="t('nav.online')"
            :description="
                total > 0 ? `${total.toLocaleString()} characters in the world.` : undefined
            "
        >
            <template #actions>
                <label class="sr-only" for="online-search">{{ t('online.searchLabel') }}</label>
                <input
                    id="online-search"
                    v-model.trim="search"
                    class="field-input max-w-48"
                    type="search"
                    :placeholder="t('online.searchPlaceholder')"
                />
            </template>
        </PageHeader>

        <AlertMessage v-if="unavailable" tone="warning" :title="t('online.duringWoe')">
            This listing is hidden while a siege is in progress, so castle defences cannot be
            scouted from the website.
        </AlertMessage>

        <template v-else>
            <DataTable
                :columns="columns"
                :rows="characters"
                :row-key="(character: Character) => character.id"
                :loading="loading"
                :error="error"
                empty-:title="t('online.empty')"
                empty-description="Characters appear here while they are in the world."
                caption="Characters currently online"
            >
                <template #retry
                    ><AppButton size="sm" @click="load">{{
                        t('common.retry')
                    }}</AppButton></template
                >

                <template #[`cell:name`]="{ row }">
                    <span class="font-medium">{{ row.name }}</span>
                </template>

                <template #[`cell:job`]="{ row }">
                    <span class="text-[var(--text-secondary)]">{{ row.job_name }}</span>
                </template>

                <template #[`cell:level`]="{ row }">
                    {{ row.base_level }}
                    <span class="text-[var(--text-muted)]">/ {{ row.job_level }}</span>
                </template>

                <template #[`cell:map`]="{ row }">
                    <span class="text-[var(--text-secondary)]">{{ row.map ?? '—' }}</span>
                </template>

                <template #[`cell:guild`]="{ row }">
                    <span v-if="row.guild?.name" class="text-[var(--text-secondary)]">
                        {{ row.guild.name }}
                    </span>
                    <span v-else class="text-[var(--text-muted)]">—</span>
                </template>
            </DataTable>

            <nav
                v-if="lastPage > 1"
                :aria-label="t('common.pagination')"
                class="mt-3 flex items-center justify-between gap-3"
            >
                <AppButton size="sm" :disabled="page <= 1" @click="goToPage(page - 1)">
                    {{ t('common.previous') }}
                </AppButton>

                <p class="tabular text-[0.8125rem] text-[var(--text-muted)]" aria-live="polite">
                    Page {{ page }} of {{ lastPage }}
                </p>

                <AppButton size="sm" :disabled="page >= lastPage" @click="goToPage(page + 1)">
                    {{ t('common.next') }}
                </AppButton>
            </nav>
        </template>
    </div>
</template>
