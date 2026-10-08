<script setup lang="ts">
import { computed } from 'vue'
import StateBlock from '../components/ui/StateBlock.vue'
import { useClassShowcaseData } from './data'
import type { ClassShowcaseProps } from './contracts'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * How the player base is distributed across job classes.
 *
 * A real `GROUP BY class` over the character table, with names resolved
 * server-side. On a server with no characters it renders an empty state rather
 * than a list of classes nobody plays -- the alternative would be decoration
 * dressed as data.
 */
const props = withDefaults(defineProps<ClassShowcaseProps>(), {
    limit: 8,
})

const showcase = useClassShowcaseData(props.limit)

/** Share of the most popular class, for a proportional bar. */
function share(characters: number): number {
    return showcase.value.busiest === 0
        ? 0
        : Math.round((characters / showcase.value.busiest) * 100)
}

/* The default lives here rather than in defineProps, which is hoisted out
 * of setup() and so cannot call t(). */
const headingText = computed(() => props.heading ?? t('classes.heading'))
</script>

<template>
    <section class="mx-auto max-w-6xl px-4 py-10" aria-labelledby="block-classes">
        <h2 id="block-classes" class="mb-4 text-lg font-semibold">{{ headingText }}</h2>

        <StateBlock
            v-if="showcase.state.error"
            variant="error"
            :title="t('classes.unavailable')"
            :description="showcase.state.error"
        />
        <StateBlock
            v-else-if="showcase.state.loading"
            variant="loading"
            :title="t('common.counting')"
        />
        <StateBlock
            v-else-if="showcase.state.empty"
            variant="empty"
            :title="t('classes.empty')"
            description="Class popularity appears here once characters have been created."
        />

        <ul v-else class="grid gap-2 sm:grid-cols-2">
            <li
                v-for="entry in showcase.classes"
                :key="entry.job_id"
                class="panel flex items-center gap-3 px-3.5 py-2.5"
            >
                <span class="flex-1 font-medium">{{ entry.job_name }}</span>

                <!-- Decorative: the figure beside it carries the information. -->
                <span
                    class="hidden h-1.5 w-28 overflow-hidden rounded-full bg-[var(--surface-sunken)] sm:block"
                    aria-hidden="true"
                >
                    <span
                        class="block h-full bg-[var(--color-accent-500)]"
                        :style="{ width: `${share(entry.characters)}%` }"
                    />
                </span>

                <span class="tabular w-14 text-right text-sm text-[var(--text-secondary)]">
                    {{ entry.characters.toLocaleString() }}
                </span>
            </li>
        </ul>
    </section>
</template>
