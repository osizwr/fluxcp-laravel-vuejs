<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import FormField from '../components/ui/FormField.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import StatTile from '../components/ui/StatTile.vue'
import StateBlock from '../components/ui/StateBlock.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { api, ApiError } from '../services/api'
import { useAuthStore } from '../stores/auth'
import type { Character } from '../types/api'

/**
 * One character, and the maintenance its owner may do to it.
 *
 * Every action here is refused by the server while the character is online,
 * so the controls are disabled rather than hidden: a disabled button with an
 * explanation tells somebody why, where a missing one leaves them looking for
 * it.
 */
const route = useRoute()
const auth = useAuthStore()

type Preferences = Record<string, boolean>

const character = ref<Character | null>(null)
const preferences = ref<Preferences>({})
const loading = ref(true)
const error = ref<string | null>(null)

/** The last action's outcome, success or refusal. */
const notice = ref<{ tone: 'success' | 'error'; message: string } | null>(null)
const busy = ref<string | null>(null)

const slot = ref(1)

const isOnline = computed(() => character.value?.online === true)

const PREFERENCE_LABELS: Record<string, string> = {
    HideFromWhosOnline: 'Hide this character from the who-is-online list',
    HideMapFromWhosOnline: 'Hide which map this character is on',
    HideFromZenyRanking: 'Hide this character from the zeny ladder',
}

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<{ data: Character; meta: { preferences: Preferences } }>(
            `characters/${route.params.id}`,
        )

        character.value = response.data
        preferences.value = response.meta.preferences
        slot.value = response.data.slot + 1
    } catch (caught) {
        error.value =
            caught instanceof ApiError && caught.isForbidden
                ? 'That is not your character.'
                : 'This character could not be loaded.'
        character.value = null
    } finally {
        loading.value = false
    }
}

/**
 * Runs one maintenance action and reports what happened.
 *
 * The server answers a refusal with 422 and a message naming the obstacle, so
 * that message is shown as-is rather than replaced with a generic one.
 */
async function act(key: string, call: () => Promise<unknown>): Promise<void> {
    busy.value = key
    notice.value = null

    try {
        const response = (await call()) as { message?: string }

        notice.value = { tone: 'success', message: response.message ?? 'Done.' }
        await load()
    } catch (caught) {
        notice.value = {
            tone: 'error',
            message:
                caught instanceof ApiError
                    ? caught.message
                    : 'That could not be done. Please try again.',
        }
    } finally {
        busy.value = null
    }
}

const id = computed(() => route.params.id)

function changeSlot(): void {
    void act('slot', () => api.put(`characters/${id.value}/slot`, { slot: slot.value }))
}

function resetLook(): void {
    void act('look', () => api.post(`characters/${id.value}/reset-look`))
}

function resetPosition(): void {
    void act('position', () => api.post(`characters/${id.value}/reset-position`))
}

function divorce(): void {
    void act('divorce', () => api.post(`characters/${id.value}/divorce`))
}

function savePreferences(): void {
    void act('prefs', () =>
        api.put(`characters/${id.value}/preferences`, { ...preferences.value }),
    )
}

onMounted(load)
watch(() => route.params.id, load)
</script>

<template>
    <div class="py-6">
        <StateBlock v-if="loading" variant="loading" title="Loading character…" />

        <StateBlock
            v-else-if="error || !character"
            variant="error"
            title="Character unavailable"
            :description="error ?? ''"
        >
            <template #action><AppButton to="/characters">My characters</AppButton></template>
        </StateBlock>

        <template v-else>
            <PageHeader :title="character.name" :description="character.job_name">
                <template #actions>
                    <StatusPill
                        :state="character.online ? 'up' : 'down'"
                        :label="character.online ? 'Online' : 'Offline'"
                    />
                </template>
            </PageHeader>

            <AlertMessage v-if="notice" :tone="notice.tone" class="mt-4">
                {{ notice.message }}
            </AlertMessage>

            <AlertMessage v-if="isOnline" tone="warning" class="mt-4">
                This character is logged in. Nothing below can be changed until they log out —
                the game server holds the character in memory and would overwrite the change.
            </AlertMessage>

            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatTile label="Base level" :value="character.base_level.toLocaleString()" />
                <StatTile label="Job level" :value="character.job_level.toLocaleString()" />
                <StatTile label="Zeny" :value="character.zeny.toLocaleString()" />
                <StatTile label="Slot" :value="String(character.slot + 1)" />
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <section class="panel p-4">
                    <h2 class="text-base font-semibold tracking-tight">Slot</h2>
                    <p class="mt-0.5 mb-3 text-sm text-[var(--text-secondary)]">
                        Move this character to another slot on the character select screen.
                        Whoever is in that slot swaps with them.
                    </p>

                    <form class="flex items-end gap-2" @submit.prevent="changeSlot">
                        <FormField label="Slot number" class="flex-1">
                            <template #default="{ id: fieldId }">
                                <input
                                    :id="fieldId"
                                    v-model.number="slot"
                                    class="field-input"
                                    type="number"
                                    min="1"
                                    :disabled="isOnline"
                                />
                            </template>
                        </FormField>

                        <AppButton
                            type="submit"
                            :disabled="isOnline"
                            :loading="busy === 'slot'"
                        >
                            Move
                        </AppButton>
                    </form>
                </section>

                <section class="panel p-4">
                    <h2 class="text-base font-semibold tracking-tight">Repairs</h2>
                    <p class="mt-0.5 mb-3 text-sm text-[var(--text-secondary)]">
                        For a character who cannot log in — usually a missing sprite, or being
                        stuck somewhere the client cannot load.
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <AppButton
                            :disabled="isOnline"
                            :loading="busy === 'look'"
                            @click="resetLook"
                        >
                            Reset appearance
                        </AppButton>

                        <AppButton
                            :disabled="isOnline"
                            :loading="busy === 'position'"
                            @click="resetPosition"
                        >
                            Return to save point
                        </AppButton>
                    </div>

                    <p class="mt-3 text-[0.8125rem] text-[var(--text-muted)]">
                        Resetting the appearance also unequips everything, which is usually the
                        point.
                    </p>
                </section>

                <section v-if="character.is_married" class="panel p-4">
                    <h2 class="text-base font-semibold tracking-tight">Marriage</h2>
                    <p class="mt-0.5 mb-3 text-sm text-[var(--text-secondary)]">
                        Both partners have to be logged out. This cannot be undone from here.
                    </p>

                    <AppButton
                        variant="danger"
                        :disabled="isOnline"
                        :loading="busy === 'divorce'"
                        @click="divorce"
                    >
                        Divorce
                    </AppButton>
                </section>

                <section class="panel p-4">
                    <h2 class="text-base font-semibold tracking-tight">Privacy</h2>
                    <p class="mt-0.5 mb-3 text-sm text-[var(--text-secondary)]">
                        What this character reveals on the public pages.
                    </p>

                    <form class="space-y-2.5" @submit.prevent="savePreferences">
                        <label
                            v-for="(label, key) in PREFERENCE_LABELS"
                            :key="key"
                            class="flex items-start gap-2 text-sm"
                        >
                            <input
                                v-model="preferences[key]"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded"
                            />
                            <span>{{ label }}</span>
                        </label>

                        <AppButton type="submit" :loading="busy === 'prefs'">
                            Save preferences
                        </AppButton>
                    </form>
                </section>
            </div>

            <AppButton :to="auth.isAuthenticated ? '/characters' : '/'" class="mt-5">
                Back
            </AppButton>
        </template>
    </div>
</template>
