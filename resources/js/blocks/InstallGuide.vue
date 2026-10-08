<script setup lang="ts">
import { computed } from 'vue'
import { useDownloadsData } from './data'
import type { DownloadsProps } from './contracts'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * What to do once the download has finished.
 *
 * An ordered list, which is the whole reason this is a block rather than a
 * paragraph: the order is the information. The numbers are drawn from the
 * position rather than stored with each step, so removing the third one
 * renumbers the rest instead of leaving a list that counts 1, 2, 4.
 *
 * The discs are hidden from assistive technology because the list element
 * already conveys the sequence; announcing "3" and then "Step 3" is the same
 * fact twice.
 */
const props = defineProps<DownloadsProps>()

const downloads = useDownloadsData()

const headingText = computed(() => props.heading ?? t('downloads.stepsHeading'))
const descriptionText = computed(() => props.description ?? t('downloads.stepsBody'))
</script>

<template>
    <section
        v-if="downloads.steps.length > 0"
        class="mx-auto max-w-6xl px-4 py-10"
        aria-labelledby="block-install-guide"
    >
        <h2 id="block-install-guide" class="text-lg font-semibold">{{ headingText }}</h2>

        <p v-if="descriptionText" class="mt-0.5 text-sm text-[var(--text-secondary)]">
            {{ descriptionText }}
        </p>

        <ol class="mt-4 grid gap-3">
            <li
                v-for="(step, index) in downloads.steps"
                :key="step.title"
                class="panel flex items-start gap-3 p-4"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-[var(--color-accent-600)] text-[0.8125rem] font-semibold text-white"
                    aria-hidden="true"
                >
                    {{ index + 1 }}
                </span>

                <div class="min-w-0">
                    <h3 class="font-semibold">{{ step.title }}</h3>

                    <p v-if="step.description" class="mt-0.5 text-sm text-[var(--text-secondary)]">
                        {{ step.description }}
                    </p>
                </div>
            </li>
        </ol>
    </section>
</template>
