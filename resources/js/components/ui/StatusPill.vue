<script setup lang="ts">
/**
 * A small status indicator.
 *
 * The state is conveyed by the label as well as the colour, because colour
 * alone is not readable by everyone. The dot is decorative and hidden from
 * assistive technology.
 */
const props = withDefaults(
    defineProps<{
        state: 'up' | 'down' | 'warn'
        label: string
        /** Render without the surrounding chip, for use inside a table cell. */
        bare?: boolean
    }>(),
    { bare: false },
)

const dotColor = {
    up: 'bg-[var(--color-up)]',
    down: 'bg-[var(--color-down)]',
    warn: 'bg-[var(--color-warn)]',
}

const chipBackground = {
    up: 'bg-[var(--status-up-bg)]',
    down: 'bg-[var(--status-down-bg)]',
    warn: 'bg-[var(--status-warn-bg)]',
}
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 text-[0.8125rem] font-medium whitespace-nowrap"
        :class="props.bare ? '' : ['rounded-full px-2 py-0.5', chipBackground[props.state]]"
    >
        <span class="size-1.5 shrink-0 rounded-full" :class="dotColor[props.state]" aria-hidden="true" />
        {{ props.label }}
    </span>
</template>
