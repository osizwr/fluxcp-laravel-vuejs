<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import DataTable, { type Column } from '../components/ui/DataTable.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { api } from '../services/api'
import { useTranslation } from '../i18n'

/**
 * What has happened to this account.
 *
 * Five listings of different shapes behind one page, chosen by the tab in the
 * route. Everything is scoped to the signed-in account by the server; nothing
 * here passes an account id, because there is no endpoint that would take one.
 */
const route = useRoute()
const router = useRouter()

type Row = Record<string, unknown>

interface Tab {
    slug: string
    label: string
    endpoint: string
    description: string
    columns: Column[]
}

const { t } = useTranslation()

const TABS: Tab[] = [
    {
        slug: 'panel-logins',
        label: t('history.websiteSignIns'),
        endpoint: 'panel-logins',
        description: t('history.websiteSignInsBody'),
        columns: [
            { key: 'at', label: t('history.when'), sort: 'date' },
            { key: 'ip', label: t('history.address'), sort: 'ip' },
            { key: 'outcome', label: t('history.outcome'), sort: 'outcome' },
        ],
    },
    {
        slug: 'game-logins',
        label: t('history.gameSignIns'),
        endpoint: 'game-logins',
        description: "Sign-ins to the game, from the server's own log.",
        columns: [
            { key: 'at', label: t('history.when'), sort: 'date' },
            { key: 'ip', label: t('history.address'), sort: 'ip' },
            { key: 'outcome', label: t('history.outcome'), sort: 'outcome' },
        ],
    },
    {
        slug: 'password-changes',
        label: t('history.passwordChanges'),
        endpoint: 'password-changes',
        description: t('history.passwordChangesBody'),
        columns: [
            { key: 'at', label: t('history.when'), sort: 'date' },
            { key: 'ip', label: t('history.address'), sort: 'ip' },
        ],
    },
    {
        slug: 'password-resets',
        label: t('history.passwordResets'),
        endpoint: 'password-resets',
        description: t('history.passwordResetsBody'),
        columns: [
            { key: 'requested_at', label: t('history.requested'), sort: 'requested' },
            { key: 'requested_from', label: t('history.address') },
            { key: 'completed', label: t('history.used'), sort: 'completed' },
        ],
    },
    {
        slug: 'email-changes',
        label: t('history.emailChanges'),
        endpoint: 'email-changes',
        description: t('history.emailChangesBody'),
        columns: [
            { key: 'requested_at', label: t('history.requested'), sort: 'requested' },
            { key: 'from', label: t('history.from'), secondary: true },
            { key: 'to', label: 'To' },
            { key: 'completed', label: t('history.confirmed'), sort: 'completed' },
        ],
    },
]

const rows = ref<Row[]>([])
const total = ref(0)
const lastPage = ref(1)
const loading = ref(true)
const error = ref<string | null>(null)

const tab = computed<Tab>(
    () => TABS.find((candidate) => candidate.slug === route.params.tab) ?? (TABS[0] as Tab),
)

const page = computed(() => Math.max(1, Number(route.query.page ?? 1)))
const sort = computed(() => (route.query.sort as string) ?? undefined)
const direction = computed<'asc' | 'desc'>(() => (route.query.direction === 'asc' ? 'asc' : 'desc'))

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<{
            data: Row[]
            meta: { total: number; last_page: number }
        }>(`account/history/${tab.value.endpoint}`, {
            sort: sort.value,
            direction: direction.value,
            page: page.value,
        })

        rows.value = response.data
        total.value = response.meta.total
        lastPage.value = response.meta.last_page
    } catch {
        error.value = t('history.error')
        rows.value = []
    } finally {
        loading.value = false
    }
}

function sortBy(column: string): void {
    void router.push({
        name: 'account-history',
        params: { tab: tab.value.slug },
        query: {
            sort: column,
            direction: sort.value === column && direction.value === 'desc' ? 'asc' : 'desc',
        },
    })
}

function goToPage(next: number): void {
    void router.push({
        name: 'account-history',
        params: { tab: tab.value.slug },
        query: { ...(route.query as Record<string, string>), page: next },
    })
}

/** Dates arrive as ISO or null; null means nothing was recorded. */
function when(value: unknown): string {
    return typeof value === 'string' ? new Date(value).toLocaleString() : '—'
}

watch(
    () => [route.params.tab, route.query],
    () => {
        void load()
    },
    { immediate: true, deep: true },
)
</script>

<template>
    <div class="py-6">
        <PageHeader :title="t('history.title')" :description="tab.description">
            <template #actions>
                <nav :aria-label="t('history.heading')" class="flex flex-wrap gap-1">
                    <AppButton
                        v-for="option in TABS"
                        :key="option.slug"
                        size="sm"
                        :variant="tab.slug === option.slug ? 'primary' : 'secondary'"
                        :to="`/account/history/${option.slug}`"
                    >
                        {{ option.label }}
                    </AppButton>
                </nav>
            </template>
        </PageHeader>

        <DataTable
            class="mt-4"
            :columns="tab.columns"
            :rows="rows"
            :row-key="(_row: Row) => JSON.stringify(_row)"
            :loading="loading"
            :error="error"
            :sort="sort ?? null"
            :direction="direction"
            :caption="tab.label"
            empty-:title="t('history.empty')"
            empty-description="This part of your history is empty."
            @sort="sortBy"
        >
            <template #cell:at="{ row }">{{ when(row.at) }}</template>
            <template #cell:ip="{ row }">{{ row.ip ?? '—' }}</template>

            <template #cell:outcome="{ row }">
                <StatusPill
                    :state="row.successful ? 'up' : 'down'"
                    :label="String(row.outcome ?? '')"
                />
            </template>

            <template #cell:requested_at="{ row }">{{ when(row.requested_at) }}</template>
            <template #cell:requested_from="{ row }">{{ row.requested_from ?? '—' }}</template>
            <template #cell:from="{ row }">{{ row.from ?? '—' }}</template>
            <template #cell:to="{ row }">{{ row.to ?? '—' }}</template>

            <template #cell:completed="{ row }">
                <StatusPill
                    :state="row.completed ? 'up' : 'warn'"
                    :label="row.completed ? when(row.completed_at ?? row.requested_at) : 'No'"
                />
            </template>
        </DataTable>

        <div
            v-if="!loading && !error && rows.length > 0"
            class="mt-4 flex items-center justify-between text-sm"
        >
            <span class="text-[var(--text-secondary)]">{{ total.toLocaleString() }} entries</span>

            <div class="flex items-center gap-2">
                <AppButton size="sm" :disabled="page <= 1" @click="goToPage(page - 1)">
                    {{ t('common.previous') }}
                </AppButton>
                <span class="text-[var(--text-muted)]">Page {{ page }} of {{ lastPage }}</span>
                <AppButton size="sm" :disabled="page >= lastPage" @click="goToPage(page + 1)">
                    {{ t('common.next') }}
                </AppButton>
            </div>
        </div>
    </div>
</template>
