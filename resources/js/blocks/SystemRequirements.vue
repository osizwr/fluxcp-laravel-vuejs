<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { useDownloadsData } from './data'
import type { DownloadsProps } from './contracts'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * What a machine needs, one tab per set of figures.
 *
 * Tabs rather than three stacked tables because the sets are alternatives --
 * somebody reads the one that matches the device in front of them, and
 * stacking them means scrolling past two that do not apply. A single
 * configured group renders as a plain table, since a tablist with one tab is
 * furniture.
 *
 * Keyboard behaviour follows the tabs pattern: arrows move between tabs, Home
 * and End jump to the ends, and only the selected tab is in the tab order, so
 * a visitor tabbing through the page steps over the set rather than through
 * it.
 */
const props = defineProps<DownloadsProps>()

const downloads = useDownloadsData()

const headingText = computed(() => props.heading ?? t('downloads.requirementsHeading'))
const descriptionText = computed(() => props.description ?? t('downloads.requirementsBody'))

const groups = computed(() => downloads.value.requirements)

const active = ref(0)

/* A composition could pass a different payload in a test, and an index left
 * pointing past the end would render no panel at all. */
watch(groups, (list) => {
    if (active.value >= list.length) {
        active.value = 0
    }
})

const tabs = useTemplateRef<HTMLButtonElement[]>('tabs')

/**
 * Select a tab and put the focus on it.
 *
 * Wraps at both ends, which is what the pattern specifies and what somebody
 * holding an arrow key expects.
 */
function select(index: number): void {
    const count = groups.value.length

    if (count === 0) {
        return
    }

    const next = ((index % count) + count) % count

    active.value = next
    tabs.value?.[next]?.focus()
}

function onKeydown(event: KeyboardEvent, index: number): void {
    const handlers: Record<string, () => void> = {
        ArrowRight: () => select(index + 1),
        ArrowDown: () => select(index + 1),
        ArrowLeft: () => select(index - 1),
        ArrowUp: () => select(index - 1),
        Home: () => select(0),
        End: () => select(groups.value.length - 1),
    }

    const handler = handlers[event.key]

    if (handler === undefined) {
        return
    }

    // Arrow keys would otherwise scroll the page out from under the tabs.
    event.preventDefault()
    handler()
}

function tabId(index: number): string {
    return `requirements-tab-${index}`
}

function panelId(index: number): string {
    return `requirements-panel-${index}`
}
</script>

<template>
    <section
        v-if="groups.length > 0"
        class="mx-auto max-w-6xl px-4 py-10"
        aria-labelledby="block-system-requirements"
    >
        <h2 id="block-system-requirements" class="text-lg font-semibold">{{ headingText }}</h2>

        <p v-if="descriptionText" class="mt-0.5 text-sm text-[var(--text-secondary)]">
            {{ descriptionText }}
        </p>

        <div class="panel mt-4 overflow-hidden">
            <div
                v-if="groups.length > 1"
                class="flex flex-wrap gap-1 border-b border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-1.5"
                role="tablist"
                :aria-label="headingText"
            >
                <button
                    v-for="(group, index) in groups"
                    :id="tabId(index)"
                    ref="tabs"
                    :key="group.heading"
                    type="button"
                    role="tab"
                    :aria-selected="index === active"
                    :aria-controls="panelId(index)"
                    :tabindex="index === active ? 0 : -1"
                    class="rounded-[var(--radius-panel)] px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        index === active
                            ? 'bg-[var(--surface-raised)] text-[var(--text-primary)] shadow-sm'
                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]'
                    "
                    @click="active = index"
                    @keydown="onKeydown($event, index)"
                >
                    {{ group.heading }}
                </button>
            </div>

            <div
                v-for="(group, index) in groups"
                v-show="index === active"
                :id="panelId(index)"
                :key="group.heading"
                role="tabpanel"
                :aria-labelledby="groups.length > 1 ? tabId(index) : undefined"
                :aria-label="groups.length > 1 ? undefined : group.heading"
                tabindex="0"
            >
                <h3
                    v-if="groups.length === 1"
                    class="border-b border-[var(--border-subtle)] px-4 py-2.5 text-[0.8125rem] font-semibold tracking-wide uppercase text-[var(--text-secondary)]"
                >
                    {{ group.heading }}
                </h3>

                <!-- A specification sheet is label-and-value pairs rather than
                     a grid of records, so a description list says what it is
                     more accurately than a table would. -->
                <dl>
                    <div
                        v-for="(row, rowIndex) in group.rows"
                        :key="row.label"
                        class="grid gap-0.5 px-4 py-2.5 sm:grid-cols-[14rem_1fr] sm:gap-4"
                        :class="rowIndex === 0 ? '' : 'border-t border-[var(--border-subtle)]'"
                    >
                        <dt class="text-[0.8125rem] font-medium text-[var(--text-secondary)]">
                            {{ row.label }}
                        </dt>
                        <dd class="text-sm">{{ row.value }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>
</template>
