<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useFeatureData } from './data'
import type { FeatureProps } from './contracts'

/**
 * What the server offers.
 *
 * Entirely operator-authored, from config/game.php, because the panel cannot
 * know what a given rAthena install has enabled. Renders nothing when the list
 * is empty rather than inventing features, and an entry without a url is plain
 * text rather than a dead link.
 */
const props = withDefaults(defineProps<FeatureProps>(), { heading: 'Why play here' })

const features = useFeatureData()
</script>

<template>
    <section
        v-if="!features.state.empty"
        class="mx-auto max-w-6xl px-4 py-10"
        aria-labelledby="block-features"
    >
        <h2 id="block-features" class="mb-4 text-lg font-semibold">{{ props.heading }}</h2>

        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="feature in features.items" :key="feature.title" class="panel p-4">
                <component
                    :is="feature.url ? RouterLink : 'div'"
                    :to="feature.url ?? undefined"
                    class="block"
                >
                    <h3 class="font-semibold">{{ feature.title }}</h3>
                    <p v-if="feature.description" class="mt-1 text-sm text-[var(--text-secondary)]">
                        {{ feature.description }}
                    </p>
                </component>
            </li>
        </ul>
    </section>
</template>
