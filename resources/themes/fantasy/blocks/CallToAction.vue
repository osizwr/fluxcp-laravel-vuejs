<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue'
import { useCallToActionData } from '@/blocks/data'
import type { CallToActionProps } from '@/blocks/contracts'
import GameMark from '../components/GameMark.vue'

/**
 * Fantasy — the closing call to action.
 *
 * Both actions come from the contract and point only at routes or configured
 * links that exist, and they change with the viewer's session: someone already
 * signed in is pointed at their characters rather than at a sign-in form.
 */
const props = defineProps<CallToActionProps>()

const cta = useCallToActionData(props.heading, props.description)
</script>

<template>
    <section class="relative isolate overflow-hidden border-t border-[var(--border-subtle)]">
        <div
            class="absolute inset-0 -z-10"
            aria-hidden="true"
            style="
                background-image: radial-gradient(
                    700px 320px at 50% 100%,
                    color-mix(in oklab, var(--color-accent-600) 16%, transparent),
                    transparent 70%
                );
            "
        />

        <div class="mx-auto flex max-w-3xl flex-col items-center px-4 py-16 text-center">
            <GameMark :size="44" />

            <h2 class="mt-5 text-2xl font-semibold tracking-[0.07em] uppercase">{{ cta.title }}</h2>

            <p class="mt-3 max-w-md text-[var(--text-secondary)]">{{ cta.description }}</p>

            <div class="mt-7 flex flex-wrap justify-center gap-2.5">
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
