<script setup lang="ts">
import AppButton from '../components/ui/AppButton.vue'
import { useHeroData } from './data'
import type { HeroProps } from './contracts'

/**
 * The core hero.
 *
 * Branding comes from configuration, and both actions point only at routes
 * that exist -- so there is no "create account" button while registration has
 * no page. A call to action that 404s is worse than a modest one.
 */
const props = withDefaults(defineProps<HeroProps>(), { showStatus: true })

const hero = useHeroData(props)
</script>

<template>
    <section class="border-b border-[var(--border-subtle)] bg-[var(--surface-sunken)]">
        <div class="mx-auto max-w-6xl px-4 py-14 text-center">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ hero.title }}</h1>

            <p v-if="hero.description" class="mx-auto mt-3 max-w-xl text-[var(--text-secondary)]">
                {{ hero.description }}
            </p>

            <p
                v-if="props.showStatus && hero.playersOnline !== null"
                class="tabular mt-4 text-sm text-[var(--text-muted)]"
            >
                <template v-if="hero.serversUp">
                    {{ hero.playersOnline.toLocaleString() }} players online
                </template>
                <template v-else>Servers are offline</template>
            </p>

            <div class="mt-7 flex flex-wrap justify-center gap-2">
                <AppButton
                    v-if="hero.primaryAction"
                    variant="primary"
                    :to="hero.primaryAction.to"
                    :href="hero.primaryAction.href"
                >
                    {{ hero.primaryAction.label }}
                </AppButton>
                <AppButton
                    v-if="hero.secondaryAction"
                    :to="hero.secondaryAction.to"
                    :href="hero.secondaryAction.href"
                >
                    {{ hero.secondaryAction.label }}
                </AppButton>
            </div>
        </div>
    </section>
</template>
