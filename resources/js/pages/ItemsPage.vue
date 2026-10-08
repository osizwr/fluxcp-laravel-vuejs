<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import DataTable, { type Column } from '../components/ui/DataTable.vue'
import FormField from '../components/ui/FormField.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import { api, ApiError } from '../services/api'
import type { Item, ListMeta, Paginated } from '../types/api'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * The item database.
 *
 * Filters live in the URL under the names the server validates, so a
 * bookmarked search and an API call are the same request. Sorting and paging
 * go through the server too: only the current page is in the browser, and
 * sorting twenty rows of thousands would quietly lie about the order.
 */
const route = useRoute()
const router = useRouter()

const items = ref<Item[]>([])
const total = ref(0)
const lastPage = ref(1)
const loading = ref(true)
const error = ref<string | null>(null)

/** Item types as the server configures them, so the client keeps no copy. */
const types = ref<Record<string, string>>({})

const filters = reactive({
    name: (route.query.name as string) ?? '',
    type: (route.query.type as string) ?? '',
    origin: (route.query.origin as string) ?? '',
})

const page = computed(() => Math.max(1, Number(route.query.page ?? 1)))
const sort = computed(() => (route.query.sort as string) ?? 'id')
const direction = computed<'asc' | 'desc'>(() =>
    route.query.direction === 'desc' ? 'desc' : 'asc',
)

const columns = computed<Column[]>(() => [
    { key: 'id', label: 'ID', numeric: true, sort: 'id' },
    { key: 'name', label: t('common.name'), sort: 'name' },
    { key: 'type', label: t('common.type'), secondary: true, sort: 'type' },
    { key: 'slots', label: t('common.slots'), numeric: true, sort: 'slots' },
    { key: 'price', label: 'Buy', numeric: true, secondary: true, sort: 'price_buy' },
    { key: 'weight', label: t('common.weight'), numeric: true, secondary: true, sort: 'weight' },
])

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<Paginated<Item> & { meta: ListMeta }>('items', {
            name: filters.name || undefined,
            type: filters.type || undefined,
            origin: filters.origin || undefined,
            sort: sort.value,
            direction: direction.value,
            page: page.value,
        })

        items.value = response.data
        total.value = response.meta.total
        lastPage.value = response.meta.last_page
    } catch (caught) {
        error.value = caught instanceof ApiError ? caught.message : t('items.error')
        items.value = []
        total.value = 0
    } finally {
        loading.value = false
    }
}

/** Replaces the query, which the watcher turns into a request. */
function navigate(query: Record<string, string | number | undefined>): void {
    void router.push({ name: 'items', query })
}

function applyFilters(): void {
    navigate({
        name: filters.name || undefined,
        type: filters.type || undefined,
        origin: filters.origin || undefined,
        sort: sort.value,
        direction: direction.value,
    })
}

function sortBy(column: string): void {
    navigate({
        ...(route.query as Record<string, string>),
        // Back to the first page: page 4 of a differently sorted list is not
        // the same set of rows.
        page: undefined,
        sort: column,
        direction: sort.value === column && direction.value === 'asc' ? 'desc' : 'asc',
    })
}

function goToPage(next: number): void {
    navigate({ ...(route.query as Record<string, string>), page: next })
}

watch(
    () => route.query,
    () => {
        void load()
    },
    { immediate: true },
)

void (async () => {
    try {
        const response = await api.get<{ data: { types: Record<string, string> } }>(
            'items/vocabulary',
        )
        types.value = response.data.types
    } catch {
        // The type filter falls back to the plain name search rather than
        // leaving the form half-rendered.
        types.value = {}
    }
})()
</script>

<template>
    <div class="py-6">
        <PageHeader
            :title="t('nav.items')"
            description="Every item on the server, including its own custom entries."
        />

        <form class="panel mt-4 grid gap-3 p-4 sm:grid-cols-4" @submit.prevent="applyFilters">
            <FormField :label="t('common.name')">
                <template #default="{ id }">
                    <input
                        :id="id"
                        v-model.trim="filters.name"
                        class="field-input"
                        type="search"
                        :placeholder="t('items.namePlaceholder')"
                    />
                </template>
            </FormField>

            <FormField v-if="Object.keys(types).length > 0" :label="t('common.type')">
                <template #default="{ id }">
                    <select :id="id" v-model="filters.type" class="field-input">
                        <option value="">{{ t('items.anyType') }}</option>
                        <option v-for="(label, key) in types" :key="key" :value="key">
                            {{ label }}
                        </option>
                    </select>
                </template>
            </FormField>

            <FormField :label="t('items.source')">
                <template #default="{ id }">
                    <select :id="id" v-model="filters.origin" class="field-input">
                        <option value="">{{ t('items.everything') }}</option>
                        <option value="stock">{{ t('items.standard') }}</option>
                        <option value="custom">{{ t('items.serverOwn') }}</option>
                    </select>
                </template>
            </FormField>

            <div class="flex items-end">
                <AppButton type="submit" variant="primary" block>{{
                    t('common.search')
                }}</AppButton>
            </div>
        </form>

        <DataTable
            class="mt-4"
            :columns="columns"
            :rows="items"
            :row-key="(item: Item) => item.id"
            :loading="loading"
            :error="error"
            :sort="sort"
            :direction="direction"
            caption="Items on this server"
            empty-:title="t('items.empty')"
            empty-description="Try a broader search."
            @sort="sortBy"
        >
            <template #cell:id="{ row }">{{ row.id }}</template>

            <template #cell:name="{ row }">
                <RouterLink :to="`/items/${row.id}`" class="underline underline-offset-2">
                    {{ row.name }}
                </RouterLink>
                <span
                    v-if="row.is_custom"
                    class="ml-1.5 rounded px-1.5 py-0.5 text-[0.6875rem] font-medium text-[var(--text-muted)] ring-1 ring-[var(--border-strong)]"
                >
                    custom
                </span>
            </template>

            <template #cell:type="{ row }">{{ row.type ?? '—' }}</template>
            <template #cell:slots="{ row }">{{ row.slots }}</template>
            <template #cell:price="{ row }">
                {{ row.price.buy?.toLocaleString() ?? '—' }}
            </template>
            <template #cell:weight="{ row }">
                {{ row.weight?.toLocaleString() ?? '—' }}
            </template>
        </DataTable>

        <div
            v-if="!loading && !error && items.length > 0"
            class="mt-4 flex items-center justify-between text-sm"
        >
            <span class="text-[var(--text-secondary)]">
                {{ total.toLocaleString() }} item{{ total === 1 ? '' : 's' }}
            </span>

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
