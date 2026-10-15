<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { useDownloadsData } from './data'
import type { DownloadsProps } from './contracts'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * What a machine needs, one tab per platform.
 *
 * Tabs rather than stacked tables because the platforms are alternatives --
 * somebody reads the one that matches the device in front of them, and
 * stacking them means scrolling past two that do not apply. A single
 * configured group renders as a plain table, since a tablist with one tab is
 * furniture.
 *
 * Within a tab the sets of figures are columns, not further tabs: minimum and
 * recommended are read against each other, and a visitor deciding whether
 * their machine will do has to see both at once to decide anything.
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
    <!--
        The section is a card of its own, the same one the masthead above it
        draws, so the page reads as three white cards on the shell rather than
        as one card and then loose markup.

        Which decides what is inside it: the tab strip and the specification
        sheet are one outlined box rather than a white panel, because a white
        panel on a white card is invisible. The structure comes from the
        border and from the sunken strip across the top instead.
    -->
    <section
        v-if="groups.length > 0"
        class="mx-auto max-w-6xl px-4 py-10"
        aria-labelledby="block-system-requirements"
    >
        <div class="panel p-5 sm:p-8">
            <h2 id="block-system-requirements" class="text-lg font-semibold">{{ headingText }}</h2>

            <p v-if="descriptionText" class="mt-0.5 text-sm text-[var(--text-secondary)]">
                {{ descriptionText }}
            </p>

            <div
                class="mt-4 overflow-hidden rounded-[var(--radius-panel)] border border-[var(--border-subtle)]"
            >
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

                <!--
                    Every panel sits in the same grid cell, so the box is as
                    wide and as tall as the largest platform and keeps those
                    measurements whichever tab is selected. Switching tabs
                    used to resize the card under the pointer, which moves
                    the tab strip the visitor is still reading.

                    The panels that are not selected are hidden with
                    visibility rather than display, which is what reserves
                    the space; it also takes them out of the tab order and
                    out of the accessibility tree, so the set still reads as
                    one panel.
                -->
                <div class="grid">
                    <div
                        v-for="(group, index) in groups"
                        :id="panelId(index)"
                        :key="group.heading"
                        role="tabpanel"
                        :aria-labelledby="groups.length > 1 ? tabId(index) : undefined"
                        :aria-label="groups.length > 1 ? undefined : group.heading"
                        tabindex="0"
                        class="col-start-1 row-start-1"
                        :class="index === active ? '' : 'invisible'"
                    >
                        <h3
                            v-if="groups.length === 1"
                            class="border-b border-[var(--border-subtle)] bg-[var(--surface-sunken)] px-4 py-2.5 text-[0.8125rem] font-semibold tracking-wide uppercase text-[var(--text-secondary)]"
                        >
                            {{ group.heading }}
                        </h3>

                        <!--
                            A table rather than a description list, because a
                            group that quotes two sets of figures is a matrix:
                            the label says which figure and the column header
                            says which set, and a list of pairs cannot say the
                            second half of that. A group with one set is the same
                            table without the header row.

                            It scrolls sideways on a narrow screen rather than
                            reflowing into cards -- the whole point of two columns
                            is reading across them, and stacking them destroys
                            that.
                        -->
                        <div class="overflow-x-auto">
                            <table
                                class="w-full border-collapse text-left"
                                :class="group.columns.length > 1 ? 'min-w-[34rem]' : ''"
                            >
                                <!-- No fill on the header row: the tab strip
                                     directly above it is already sunken, and two
                                     grey bands stacked read as one smear rather
                                     than as two separate things. -->
                                <thead v-if="group.columns.length > 0">
                                    <tr class="border-b border-[var(--border-subtle)]">
                                        <!-- The corner above the labels. Empty on
                                             purpose: it heads nothing. -->
                                        <td class="px-4 py-2"></td>

                                        <th
                                            v-for="column in group.columns"
                                            :key="column"
                                            scope="col"
                                            class="px-4 py-2 text-[0.75rem] font-semibold tracking-wide uppercase text-[var(--text-secondary)]"
                                        >
                                            {{ column }}
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr
                                        v-for="(row, rowIndex) in group.rows"
                                        :key="row.label"
                                        :class="
                                            rowIndex === 0
                                                ? ''
                                                : 'border-t border-[var(--border-subtle)]'
                                        "
                                    >
                                        <th
                                            scope="row"
                                            class="px-4 py-2.5 align-top text-[0.8125rem] font-medium text-[var(--text-secondary)] sm:w-56"
                                        >
                                            {{ row.label }}
                                        </th>

                                        <td
                                            v-for="(value, columnIndex) in row.values"
                                            :key="columnIndex"
                                            class="px-4 py-2.5 align-top text-sm"
                                        >
                                            {{ value }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
