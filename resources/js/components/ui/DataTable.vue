<script setup lang="ts" generic="T">
import { computed } from 'vue'
import StateBlock from './StateBlock.vue'
import { useTranslation } from '../../i18n'

/**
 * A table with consistent loading, empty and error states.
 *
 * Generic over the row type so a page's column slots stay typed. Scrolls
 * horizontally on a narrow screen rather than reflowing into cards: these are
 * ladders and listings people compare down a column, and stacking them
 * destroys that.
 */
export interface Column {
    key: string
    label: string
    /** Right-aligned and tabular, for figures that get compared. */
    numeric?: boolean
    /** Hidden below the small breakpoint, for secondary detail. */
    secondary?: boolean
    /**
     * The name the server accepts for sorting on this column, when it accepts
     * one. Absent means the column is not sortable, so a header is plain text
     * rather than a control that does nothing.
     */
    sort?: string
}

const { t } = useTranslation()

const props = withDefaults(
    defineProps<{
        columns: Column[]
        rows: T[]
        rowKey: (row: T) => string | number
        loading?: boolean
        error?: string | null
        emptyTitle?: string
        emptyDescription?: string
        caption?: string
        /** The sort currently applied, as the server reported it. */
        sort?: string | null
        direction?: 'asc' | 'desc'
    }>(),
    {
        loading: false,
        error: null,
        sort: null,
        direction: 'asc',
    },
)

/**
 * Asks the page to re-sort. The table does not sort its own rows: only the
 * current page is in the browser, so sorting here would reorder twenty rows
 * out of thousands and quietly lie about the ranking.
 */
const emit = defineEmits<{ sort: [column: string] }>()

/** aria-sort for the header cell, which is what a screen reader announces. */
function ariaSort(column: Column): 'ascending' | 'descending' | 'none' | undefined {
    if (column.sort === undefined) {
        return undefined
    }

    if (props.sort !== column.sort) {
        return 'none'
    }

    return props.direction === 'asc' ? 'ascending' : 'descending'
}

/* The default lives here rather than in defineProps, which is hoisted out
 * of setup() and so cannot call t(). */
const emptyTitleText = computed(() => props.emptyTitle ?? t('common.nothingToShow'))
</script>

<template>
    <div class="panel overflow-hidden">
        <StateBlock
            v-if="props.loading && props.rows.length === 0"
            variant="loading"
            :title="t('common.loading')"
        />

        <StateBlock
            v-else-if="props.error"
            variant="error"
            :title="t('common.couldNotLoad')"
            :description="props.error"
        >
            <template v-if="$slots.retry" #action><slot name="retry" /></template>
        </StateBlock>

        <StateBlock
            v-else-if="props.rows.length === 0"
            variant="empty"
            :title="emptyTitleText"
            :description="props.emptyDescription"
        />

        <div v-else class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <caption v-if="props.caption" class="sr-only">
                    {{
                        props.caption
                    }}
                </caption>

                <thead>
                    <tr class="border-b border-[var(--border-subtle)] bg-[var(--surface-sunken)]">
                        <th
                            v-for="column in props.columns"
                            :key="column.key"
                            scope="col"
                            class="text-[0.75rem] font-semibold tracking-wide text-[var(--text-secondary)] uppercase"
                            :class="[
                                column.numeric ? 'text-right' : 'text-left',
                                column.secondary ? 'hidden sm:table-cell' : '',
                                column.sort === undefined ? 'px-3 py-2' : '',
                            ]"
                            :aria-sort="ariaSort(column)"
                        >
                            <button
                                v-if="column.sort !== undefined"
                                type="button"
                                class="flex w-full items-center gap-1 px-3 py-2 uppercase hover:text-[var(--text-primary)]"
                                :class="column.numeric ? 'justify-end' : ''"
                                @click="emit('sort', column.sort)"
                            >
                                {{ column.label }}
                                <span
                                    class="text-[0.625rem]"
                                    :class="props.sort === column.sort ? '' : 'opacity-0'"
                                    aria-hidden="true"
                                >
                                    {{ props.direction === 'asc' ? '\u25B2' : '\u25BC' }}
                                </span>
                            </button>

                            <template v-else>{{ column.label }}</template>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="row in props.rows"
                        :key="props.rowKey(row)"
                        class="border-b border-[var(--border-subtle)] last:border-0 hover:bg-[var(--surface-hover)]"
                    >
                        <td
                            v-for="column in props.columns"
                            :key="column.key"
                            class="px-3 py-2 align-middle"
                            :class="[
                                column.numeric ? 'text-right tabular' : '',
                                column.secondary ? 'hidden sm:table-cell' : '',
                            ]"
                        >
                            <slot :name="`cell:${column.key}`" :row="row" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
