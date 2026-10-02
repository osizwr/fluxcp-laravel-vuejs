<script setup lang="ts" generic="T">
import StateBlock from './StateBlock.vue'

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
}

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
    }>(),
    {
        loading: false,
        error: null,
        emptyTitle: 'Nothing to show',
    },
)
</script>

<template>
    <div class="panel overflow-hidden">
        <StateBlock
            v-if="props.loading && props.rows.length === 0"
            variant="loading"
            title="Loading…"
        />

        <StateBlock
            v-else-if="props.error"
            variant="error"
            title="Could not load this list"
            :description="props.error"
        >
            <template v-if="$slots.retry" #action><slot name="retry" /></template>
        </StateBlock>

        <StateBlock
            v-else-if="props.rows.length === 0"
            variant="empty"
            :title="props.emptyTitle"
            :description="props.emptyDescription"
        />

        <div v-else class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <caption v-if="props.caption" class="sr-only">{{ props.caption }}</caption>

                <thead>
                    <tr class="border-b border-[var(--border-subtle)] bg-[var(--surface-sunken)]">
                        <th
                            v-for="column in props.columns"
                            :key="column.key"
                            scope="col"
                            class="px-3 py-2 text-left text-[0.75rem] font-semibold tracking-wide text-[var(--text-secondary)] uppercase"
                            :class="[
                                column.numeric ? 'text-right' : '',
                                column.secondary ? 'hidden sm:table-cell' : '',
                            ]"
                        >
                            {{ column.label }}
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
