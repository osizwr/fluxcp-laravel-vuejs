<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import DataTable, { type Column } from '../components/ui/DataTable.vue'
import FormField from '../components/ui/FormField.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import { api, ApiError } from '../services/api'
import type { ListMeta, Monster, Paginated } from '../types/api'

const route = useRoute()
const router = useRouter()

const monsters = ref<Monster[]>([])
const total = ref(0)
const lastPage = ref(1)
const loading = ref(true)
const error = ref<string | null>(null)

const filters = reactive({
    name: (route.query.name as string) ?? '',
    levelMin: (route.query.level_min as string) ?? '',
    levelMax: (route.query.level_max as string) ?? '',
    mvp: route.query.mvp === '1',
})

const page = computed(() => Math.max(1, Number(route.query.page ?? 1)))
const sort = computed(() => (route.query.sort as string) ?? 'id')
const direction = computed<'asc' | 'desc'>(() =>
    route.query.direction === 'desc' ? 'desc' : 'asc',
)

const columns = computed<Column[]>(() => [
    { key: 'id', label: 'ID', numeric: true, sort: 'id' },
    { key: 'name', label: 'Name', sort: 'name' },
    { key: 'level', label: 'Level', numeric: true, sort: 'level' },
    { key: 'hp', label: 'HP', numeric: true, sort: 'hp' },
    { key: 'exp', label: 'Base EXP', numeric: true, secondary: true, sort: 'experience' },
])

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<Paginated<Monster> & { meta: ListMeta }>('monsters', {
            name: filters.name || undefined,
            level_min: filters.levelMin || undefined,
            level_max: filters.levelMax || undefined,
            mvp: filters.mvp ? 1 : undefined,
            sort: sort.value,
            direction: direction.value,
            page: page.value,
        })

        monsters.value = response.data
        total.value = response.meta.total
        lastPage.value = response.meta.last_page
    } catch (caught) {
        error.value =
            caught instanceof ApiError ? caught.message : 'The monster list could not be loaded.'
        monsters.value = []
        total.value = 0
    } finally {
        loading.value = false
    }
}

function navigate(query: Record<string, string | number | undefined>): void {
    void router.push({ name: 'monsters', query })
}

function applyFilters(): void {
    navigate({
        name: filters.name || undefined,
        level_min: filters.levelMin || undefined,
        level_max: filters.levelMax || undefined,
        mvp: filters.mvp ? '1' : undefined,
        sort: sort.value,
        direction: direction.value,
    })
}

function sortBy(column: string): void {
    navigate({
        ...(route.query as Record<string, string>),
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
</script>

<template>
    <div class="py-6">
        <PageHeader
            title="Monsters"
            description="Every monster on the server, custom ones included."
        />

        <form class="panel mt-4 grid gap-3 p-4 sm:grid-cols-5" @submit.prevent="applyFilters">
            <FormField label="Name" class="sm:col-span-2">
                <template #default="{ id }">
                    <input
                        :id="id"
                        v-model.trim="filters.name"
                        class="field-input"
                        type="search"
                        placeholder="Poring"
                    />
                </template>
            </FormField>

            <FormField label="Level from">
                <template #default="{ id }">
                    <input
                        :id="id"
                        v-model="filters.levelMin"
                        class="field-input"
                        type="number"
                        min="0"
                    />
                </template>
            </FormField>

            <FormField label="Level to">
                <template #default="{ id }">
                    <input
                        :id="id"
                        v-model="filters.levelMax"
                        class="field-input"
                        type="number"
                        min="0"
                    />
                </template>
            </FormField>

            <div class="flex flex-col justify-end gap-2">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="filters.mvp" type="checkbox" class="size-4 rounded" />
                    MVPs only
                </label>
                <AppButton type="submit" variant="primary" block>Search</AppButton>
            </div>
        </form>

        <DataTable
            class="mt-4"
            :columns="columns"
            :rows="monsters"
            :row-key="(monster: Monster) => monster.id"
            :loading="loading"
            :error="error"
            :sort="sort"
            :direction="direction"
            caption="Monsters on this server"
            empty-title="No monsters match those filters"
            empty-description="Try a broader search."
            @sort="sortBy"
        >
            <template #cell:id="{ row }">{{ row.id }}</template>

            <template #cell:name="{ row }">
                <RouterLink :to="`/monsters/${row.id}`" class="underline underline-offset-2">
                    {{ row.name }}
                </RouterLink>
                <span
                    v-if="row.is_mvp"
                    class="ml-1.5 rounded px-1.5 py-0.5 text-[0.6875rem] font-medium text-[var(--color-warn)] ring-1 ring-[var(--color-warn)]/40"
                >
                    MVP
                </span>
                <span
                    v-if="row.is_custom"
                    class="ml-1.5 rounded px-1.5 py-0.5 text-[0.6875rem] font-medium text-[var(--text-muted)] ring-1 ring-[var(--border-strong)]"
                >
                    custom
                </span>
            </template>

            <template #cell:level="{ row }">{{ row.level }}</template>
            <template #cell:hp="{ row }">{{ row.hp.toLocaleString() }}</template>
            <template #cell:exp="{ row }">{{ row.experience.base.toLocaleString() }}</template>
        </DataTable>

        <div
            v-if="!loading && !error && monsters.length > 0"
            class="mt-4 flex items-center justify-between text-sm"
        >
            <span class="text-[var(--text-secondary)]">
                {{ total.toLocaleString() }} monster{{ total === 1 ? '' : 's' }}
            </span>

            <div class="flex items-center gap-2">
                <AppButton size="sm" :disabled="page <= 1" @click="goToPage(page - 1)">
                    Previous
                </AppButton>
                <span class="text-[var(--text-muted)]">Page {{ page }} of {{ lastPage }}</span>
                <AppButton size="sm" :disabled="page >= lastPage" @click="goToPage(page + 1)">
                    Next
                </AppButton>
            </div>
        </div>
    </div>
</template>
