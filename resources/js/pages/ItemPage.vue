<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import StateBlock from '../components/ui/StateBlock.vue'
import { api, ApiError } from '../services/api'
import type { Item } from '../types/api'

const route = useRoute()

const item = ref<Item | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<{ data: Item }>(`items/${route.params.id}`)
        item.value = response.data
    } catch (caught) {
        error.value =
            caught instanceof ApiError && caught.status === 404
                ? 'There is no item with that id.'
                : 'This item could not be loaded.'
        item.value = null
    } finally {
        loading.value = false
    }
}

onMounted(load)
watch(() => route.params.id, load)
</script>

<template>
    <div class="py-6">
        <StateBlock v-if="loading" variant="loading" title="Loading item…" />

        <StateBlock v-else-if="error || !item" variant="error" title="Item unavailable" :description="error ?? ''">
            <template #action>
                <AppButton to="/items">Back to items</AppButton>
            </template>
        </StateBlock>

        <template v-else>
            <PageHeader :title="item.name" :description="`${item.aegis_name} · ID ${item.id}`" />

            <div class="mt-5 grid gap-5 lg:grid-cols-3">
                <section class="panel p-4 lg:col-span-2">
                    <h2 class="mb-3 text-base font-semibold tracking-tight">Statistics</h2>

                    <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Type</dt>
                            <dd class="font-medium">{{ item.type ?? '—' }}</dd>
                        </div>
                        <div v-if="item.subtype">
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Subtype</dt>
                            <dd class="font-medium">{{ item.subtype }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Slots</dt>
                            <dd class="font-medium tabular-nums">{{ item.slots }}</dd>
                        </div>
                        <div v-if="item.attack !== null">
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Attack</dt>
                            <dd class="font-medium tabular-nums">{{ item.attack }}</dd>
                        </div>
                        <div v-if="item.defense !== null">
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Defense</dt>
                            <dd class="font-medium tabular-nums">{{ item.defense }}</dd>
                        </div>
                        <div v-if="item.weapon_level !== null">
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Weapon level</dt>
                            <dd class="font-medium tabular-nums">{{ item.weapon_level }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Weight</dt>
                            <dd class="font-medium tabular-nums">
                                {{ item.weight?.toLocaleString() ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Buy / Sell</dt>
                            <dd class="font-medium tabular-nums">
                                {{ item.price.buy?.toLocaleString() ?? '—' }} /
                                {{ item.price.sell?.toLocaleString() ?? '—' }}
                            </dd>
                        </div>
                        <div v-if="item.equip_level.min !== null">
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">Required level</dt>
                            <dd class="font-medium tabular-nums">{{ item.equip_level.min }}</dd>
                        </div>
                    </dl>

                    <div v-if="item.script" class="mt-5">
                        <h3 class="mb-1.5 text-sm font-semibold">Script</h3>
                        <pre class="overflow-x-auto rounded-[var(--radius-control)] bg-[var(--surface-sunken)] p-3 text-[0.8125rem] leading-relaxed"><code>{{ item.script }}</code></pre>
                    </div>
                </section>

                <aside class="space-y-4">
                    <section v-if="item.equip_locations.length" class="panel p-4">
                        <h2 class="mb-2 text-sm font-semibold">Equipped at</h2>
                        <ul class="space-y-1 text-sm text-[var(--text-secondary)]">
                            <li v-for="location in item.equip_locations" :key="location">
                                {{ location }}
                            </li>
                        </ul>
                    </section>

                    <section v-if="item.jobs.length" class="panel p-4">
                        <h2 class="mb-2 text-sm font-semibold">Jobs</h2>
                        <p class="text-sm text-[var(--text-secondary)]">{{ item.jobs.join(', ') }}</p>
                    </section>

                    <section v-if="item.trade_restrictions.length" class="panel p-4">
                        <h2 class="mb-2 text-sm font-semibold">Restrictions</h2>
                        <ul class="space-y-1 text-sm text-[var(--text-secondary)]">
                            <li v-for="restriction in item.trade_restrictions" :key="restriction">
                                {{ restriction }}
                            </li>
                        </ul>
                    </section>

                    <section v-if="item.flags.length" class="panel p-4">
                        <h2 class="mb-2 text-sm font-semibold">Notes</h2>
                        <ul class="space-y-1 text-sm text-[var(--text-secondary)]">
                            <li v-for="flag in item.flags" :key="flag">{{ flag }}</li>
                        </ul>
                    </section>
                </aside>
            </div>

            <AppButton to="/items" class="mt-5">Back to items</AppButton>
        </template>
    </div>
</template>
