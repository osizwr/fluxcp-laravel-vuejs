<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import StatTile from '../components/ui/StatTile.vue'
import StateBlock from '../components/ui/StateBlock.vue'
import { api, ApiError } from '../services/api'
import type { Monster } from '../types/api'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

const route = useRoute()

const monster = ref<Monster | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<{ data: Monster }>(`monsters/${route.params.id}`)
        monster.value = response.data
    } catch (caught) {
        error.value =
            caught instanceof ApiError && caught.status === 404
                ? t('monsters.notFound')
                : t('monsters.oneError')
        monster.value = null
    } finally {
        loading.value = false
    }
}

onMounted(load)
watch(() => route.params.id, load)
</script>

<template>
    <div class="py-6">
        <StateBlock v-if="loading" variant="loading" :title="t('monsters.loadingOne')" />

        <StateBlock
            v-else-if="error || !monster"
            variant="error"
            :title="t('monsters.unavailable')"
            :description="error ?? ''"
        >
            <template #action>
                <AppButton to="/monsters">{{ t('monsters.back') }}</AppButton>
            </template>
        </StateBlock>

        <template v-else>
            <PageHeader
                :title="monster.name"
                :description="`${monster.aegis_name} · ID ${monster.id}`"
            />

            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatTile :label="t('common.level')" :value="monster.level.toLocaleString()" />
                <StatTile :label="t('monsters.hp')" :value="monster.hp.toLocaleString()" />
                <StatTile
                    :label="t('monsters.baseExp')"
                    :value="monster.experience.base.toLocaleString()"
                />
                <StatTile
                    :label="t('monsters.jobExp')"
                    :value="monster.experience.job.toLocaleString()"
                />
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-3">
                <section class="panel p-4 lg:col-span-2">
                    <h2 class="mb-3 text-base font-semibold tracking-tight">
                        {{ t('monsters.combat') }}
                    </h2>

                    <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                                {{ t('items.attack') }}
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ monster.attack.min.toLocaleString() }} –
                                {{ monster.attack.max.toLocaleString() }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                                {{ t('items.defense') }}
                            </dt>
                            <dd class="font-medium tabular-nums">{{ monster.defense }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                                {{ t('monsters.magicDefense') }}
                            </dt>
                            <dd class="font-medium tabular-nums">{{ monster.magic_defense }}</dd>
                        </div>
                        <div v-if="monster.is_mvp">
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                                {{ t('monsters.mvpExp') }}
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ monster.experience.mvp.toLocaleString() }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-[var(--text-muted)]">SP</dt>
                            <dd class="font-medium tabular-nums">
                                {{ monster.sp.toLocaleString() }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <aside class="space-y-4">
                    <section v-if="monster.modes.length" class="panel p-4">
                        <h2 class="mb-2 text-sm font-semibold">{{ t('monsters.behaviour') }}</h2>
                        <ul class="space-y-1 text-sm text-[var(--text-secondary)]">
                            <li v-for="mode in monster.modes" :key="mode">{{ mode }}</li>
                        </ul>
                    </section>

                    <section v-if="monster.is_custom" class="panel p-4">
                        <h2 class="mb-1 text-sm font-semibold">{{ t('monsters.custom') }}</h2>
                        <p class="text-sm text-[var(--text-secondary)]">
                            This entry comes from the server's own monster table rather than the
                            standard one.
                        </p>
                    </section>
                </aside>
            </div>

            <AppButton to="/monsters" class="mt-5">{{ t('monsters.back') }}</AppButton>
        </template>
    </div>
</template>
