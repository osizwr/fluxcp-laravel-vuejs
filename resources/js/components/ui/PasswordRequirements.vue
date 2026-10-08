<script setup lang="ts">
import { computed } from 'vue'
import { passwordRequirements, useAccounts } from '../../composables/useAccounts'
import { useTranslation } from '../../i18n'

const { t } = useTranslation()

/**
 * The password policy as a live checklist, shown while the field has focus.
 *
 * Replaces a sentence under the field that stated the rules and then said
 * nothing more. A visitor who had met three of four requirements had to re-read
 * the sentence and audit their own password against it; here each rule answers
 * for itself as they type.
 *
 * It floats rather than sitting in the flow, so appearing and disappearing does
 * not push the rest of the form up and down under the cursor.
 *
 * The rules come from `passwordRequirements`, which mirrors the server's
 * validation. Nothing is decided here -- the form submits whatever is typed and
 * the server rules on it, exactly as before.
 */
const props = withDefaults(
    defineProps<{
        password: string
        /** Only where the page knows it; the policy may forbid it inside. */
        username?: string
        /** The id the field's `aria-describedby` points at. */
        id?: string
    }>(),
    { username: '', id: undefined },
)

const { config } = useAccounts()

const requirements = computed(() =>
    passwordRequirements(config.value.password, props.password, props.username),
)
</script>

<template>
    <div
        :id="props.id"
        class="field-requirements absolute inset-x-0 z-20 mt-1 rounded-[var(--radius-panel)] border border-[var(--border-subtle)] bg-[var(--surface-raised)] p-2.5 shadow-lg"
    >
        <p class="mb-1.5 text-[0.75rem] font-semibold text-[var(--text-secondary)]">
            {{ t('password.heading') }}
        </p>

        <ul class="space-y-1">
            <li
                v-for="rule in requirements"
                :key="rule.key"
                class="flex items-center gap-2 text-[0.8125rem]"
                :class="rule.met ? 'text-[var(--color-up)]' : 'text-[var(--text-muted)]'"
            >
                <!--
                    Drawn rather than coloured text alone: colour is not
                    available to everyone, so met and unmet differ in shape as
                    well as in hue, and the state is spelled out for a screen
                    reader beside it.
                -->
                <svg
                    class="size-3.5 shrink-0"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        v-if="rule.met"
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.86-9.9a.75.75 0 00-1.22-.86l-3.24 4.53-1.6-1.6a.75.75 0 10-1.06 1.06l2.22 2.22a.75.75 0 001.15-.1l3.75-5.25z"
                        clip-rule="evenodd"
                    />
                    <path
                        v-else
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm0-1.5a6.5 6.5 0 110-13 6.5 6.5 0 010 13z"
                        clip-rule="evenodd"
                    />
                </svg>

                <span>{{ rule.label }}</span>
                <span class="sr-only">{{
                    rule.met ? t('password.met') : t('password.notMet')
                }}</span>
            </li>
        </ul>
    </div>
</template>
