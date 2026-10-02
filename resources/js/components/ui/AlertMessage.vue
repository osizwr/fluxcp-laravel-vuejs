<script setup lang="ts">
/**
 * An inline message. Uses role="alert" for errors so it is announced, and a
 * quieter role for informational notes so it is not.
 */
const props = withDefaults(
    defineProps<{ tone?: 'info' | 'error' | 'warning'; title?: string }>(),
    { tone: 'info' },
)

const toneClasses = {
    info: 'border-[var(--border-strong)] bg-[var(--surface-sunken)]',
    error: 'border-[var(--color-down)]/35 bg-[var(--status-down-bg)]',
    warning: 'border-[var(--color-warn)]/40 bg-[var(--status-warn-bg)]',
}
</script>

<template>
    <div
        class="rounded-[var(--radius-panel)] border px-3 py-2.5 text-sm"
        :class="toneClasses[props.tone]"
        :role="props.tone === 'error' ? 'alert' : 'status'"
    >
        <p v-if="props.title" class="mb-0.5 font-medium">{{ props.title }}</p>
        <slot />
    </div>
</template>
