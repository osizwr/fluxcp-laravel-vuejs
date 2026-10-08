<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useShell } from '@/composables/useShell'
import { useAuthStore } from '@/stores/auth'
import { useTranslation } from '@/i18n'

/**
 * Skyward — the account menu.
 *
 * Takes the masthead's emphatic slot once the visitor is signed in. Play Now is
 * an invitation, and it stops being one the moment it is accepted: somebody with
 * an account does not need to be asked to make one, and the download it points
 * at is already a top-level navigation entry. What they want in that corner is
 * their own name and the two or three things they can do with it.
 *
 * Drawn as the accent pill the Play Now button was, so the masthead keeps the
 * same shape either way -- one quiet action and one loud one signed out, one
 * loud one signed in -- rather than rearranging itself around the visitor's
 * state.
 *
 * The dismissal behaviour mirrors LanguagePicker: Escape and a press outside,
 * both bound on the document because a panel that hangs over the page cannot
 * rely on receiving the press that should close it. Focus returns to the
 * trigger on Escape so a keyboard visitor is not dropped at the top of the
 * document.
 */
const { t } = useTranslation()
const auth = useAuthStore()
const { signingOut, signOut } = useShell()

/**
 * Announced when the panel opens, so the masthead can put its mobile sheet
 * away.
 *
 * Both hang off the same bar at the same offset, and on a phone they overlap.
 * The pill is the narrower, more deliberate of the two presses, so it is the
 * one that wins. The other direction needs nothing: pressing the hamburger is a
 * press outside this component, which already closes it.
 */
const emit = defineEmits<{ opened: [] }>()

const open = ref(false)
const root = ref<HTMLElement | null>(null)
const trigger = ref<HTMLElement | null>(null)

function toggle(): void {
    open.value = !open.value

    if (open.value) {
        emit('opened')
    }
}

/**
 * The visitor's own name, or the generic word for the page behind it.
 *
 * The account is loaded separately from the session being established, so there
 * is a moment where the panel knows somebody is signed in but not yet who. An
 * empty pill in the masthead's loudest position is worse than a placeholder.
 */
const name = computed(() => auth.account?.username ?? t('nav.account'))

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && open.value) {
        open.value = false
        trigger.value?.focus()
    }
}

function onPointerDown(event: Event): void {
    const target = event.target as Node | null

    if (open.value && target !== null && root.value?.contains(target) !== true) {
        open.value = false
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    document.addEventListener('pointerdown', onPointerDown, true)
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown)
    document.removeEventListener('pointerdown', onPointerDown, true)
})
</script>

<template>
    <div ref="root" class="sky-account">
        <button
            ref="trigger"
            type="button"
            class="sky-account__trigger"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="toggle"
        >
            <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    d="M10 9.2a3.6 3.6 0 100-7.2 3.6 3.6 0 000 7.2zm0 1.5c-3.3 0-6 1.9-6 4.3 0 .6.5 1 1.1 1h9.8c.6 0 1.1-.4 1.1-1 0-2.4-2.7-4.3-6-4.3z"
                />
            </svg>
            <span class="sky-account__name">{{ name }}</span>
            <!--
                The chevron turns over while the panel is open. It is the only
                thing on the pill that says whether pressing it again will open
                or close, now that the label is a name rather than a verb.
            -->
            <svg
                class="sky-account__chevron size-3 shrink-0"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>

        <Transition name="sky-sheet">
            <div v-if="open" class="sky-account__menu" role="menu">
                <RouterLink
                    to="/account"
                    role="menuitem"
                    class="sky-account__item"
                    @click="open = false"
                >
                    {{ t('nav.myProfile') }}
                </RouterLink>

                <button
                    type="button"
                    role="menuitem"
                    class="sky-account__item"
                    :disabled="signingOut"
                    :aria-busy="signingOut || undefined"
                    @click="signOut"
                >
                    {{ t('nav.signOut') }}
                </button>
            </div>
        </Transition>
    </div>
</template>
