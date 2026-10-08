<script setup lang="ts">
import { computed } from 'vue'
import { useAppearance } from '@/composables/useAppearance'
import { useTranslation } from '@/i18n'

/**
 * Skyward — day and night.
 *
 * A control of its own rather than an entry in a menu, and set apart from the
 * two actions beside it by a hairline: it does not belong with them. Log in
 * and Create Account are the two things the masthead is asking the visitor to
 * do, and a third pill in that group would read as a third thing to do rather
 * than as a preference about the page they are already on.
 *
 * ---------------------------------------------------------------------------
 * Why a toggle button and not two radio-like states
 * ---------------------------------------------------------------------------
 *
 * The accessible name stays "Night mode" in both states and `aria-pressed`
 * carries whether it is on. A label that flips to "Day mode" when night is
 * active names the *destination*, which is the convention for a link and the
 * wrong one for a switch -- a screen reader then announces a control called
 * "Day mode" on a page that is in day mode, and nothing says which way it is
 * currently set.
 *
 * The icon shows the destination, because that is the convention sighted
 * people have: a moon to go dark, a sun to come back.
 *
 * It is disabled while the page is fading. Not to protect the composable --
 * that refuses a second change on its own -- but because a control that
 * keeps accepting presses through a second-long animation invites
 * somebody to press it three times, and the three presses that did nothing
 * are the ones they will remember. `disabled` also takes it out of the tab
 * order for that moment, which is the same answer given to the keyboard.
 */
const { t } = useTranslation()
const { appearance, changing, toggleAppearance } = useAppearance()

const isNight = computed(() => appearance.value === 'dark')
</script>

<template>
    <div class="flex items-center gap-1.5 sm:gap-2">
        <!--
            The separator. Decorative, so it is hidden: the grouping it draws
            is already carried for a screen reader by the button having its
            own name and pressed state.
        -->
        <span class="hidden h-6 w-px bg-[var(--border-subtle)] sm:block" aria-hidden="true" />

        <button
            type="button"
            class="sky-appearance"
            :class="{ 'sky-appearance--night': isNight }"
            :disabled="changing"
            :aria-pressed="isNight"
            :aria-label="t('nav.nightMode')"
            :title="t('nav.nightMode')"
            @click="toggleAppearance"
        >
            <!--
                Both icons are drawn and one is revealed, rather than swapping
                the path with v-if. Swapping replaces the element, which
                restarts nothing on its own but gives the browser nothing to
                interpolate between; drawing both lets the pair cross-fade and
                rotate, which is what makes this read as one switch rather
                than two buttons taking turns.
            -->
            <svg
                class="sky-appearance__icon sky-appearance__icon--moon"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M20 14.2A8.2 8.2 0 0 1 9.8 4 8.4 8.4 0 1 0 20 14.2Z" />
            </svg>

            <svg
                class="sky-appearance__icon sky-appearance__icon--sun"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <circle cx="12" cy="12" r="4" />
                <path
                    d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"
                />
            </svg>
        </button>
    </div>
</template>
