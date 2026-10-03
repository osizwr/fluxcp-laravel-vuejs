<script setup lang="ts">
import AppButton from '../components/ui/AppButton.vue'
import { useCallToActionData } from './data'
import type { CallToActionProps } from './contracts'

/**
 * A closing call to action.
 *
 * Both actions point at routes or configured links that exist, and change with
 * the viewer's session: a signed-in visitor is pointed at their characters
 * rather than at a sign-in form they do not need.
 */
const props = defineProps<CallToActionProps>()

const cta = useCallToActionData(props.heading, props.description)
</script>

<template>
    <section class="border-t border-[var(--border-subtle)] bg-[var(--surface-sunken)]">
        <div class="mx-auto max-w-6xl px-4 py-12 text-center">
            <h2 class="text-2xl font-semibold tracking-tight">{{ cta.title }}</h2>
            <p class="mx-auto mt-2 max-w-lg text-[var(--text-secondary)]">{{ cta.description }}</p>

            <div class="mt-6 flex flex-wrap justify-center gap-2">
                <AppButton
                    v-if="cta.action"
                    variant="primary"
                    :to="cta.action.to"
                    :href="cta.action.href"
                >
                    {{ cta.action.label }}
                </AppButton>
                <AppButton
                    v-if="cta.secondaryAction"
                    :to="cta.secondaryAction.to"
                    :href="cta.secondaryAction.href"
                >
                    {{ cta.secondaryAction.label }}
                </AppButton>
            </div>
        </div>
    </section>
</template>
