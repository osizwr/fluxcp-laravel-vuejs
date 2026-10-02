<script setup lang="ts">
/**
 * The shared shell for the loading, empty and error states of a panel.
 *
 * One component rather than three near-identical ones, so these states look
 * consistent wherever they appear instead of each page inventing its own.
 */
const props = withDefaults(
    defineProps<{
        variant: 'loading' | 'empty' | 'error'
        title: string
        description?: string
    }>(),
    {},
)
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center"
        :aria-busy="props.variant === 'loading' || undefined"
        :role="props.variant === 'error' ? 'alert' : undefined"
    >
        <span
            v-if="props.variant === 'loading'"
            class="size-5 animate-spin rounded-full border-2 border-[var(--border-strong)] border-t-[var(--color-accent-500)]"
            aria-hidden="true"
        />

        <p class="text-sm font-medium" :class="props.variant === 'error' ? 'text-[var(--color-down)]' : ''">
            {{ props.title }}
        </p>

        <p v-if="props.description" class="max-w-sm text-[0.8125rem] text-[var(--text-muted)]">
            {{ props.description }}
        </p>

        <div v-if="$slots.action" class="mt-1.5">
            <slot name="action" />
        </div>
    </div>
</template>
