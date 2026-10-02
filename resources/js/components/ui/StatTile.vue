<script setup lang="ts">
/**
 * A single headline figure.
 *
 * Deliberately plain: a label, a number, and optionally one line of context.
 * The figure is tabular so a row of these lines up.
 */
const props = defineProps<{
    label: string
    value: string | number
    context?: string
}>()
</script>

<template>
    <div class="panel px-3.5 py-3">
        <p class="text-[0.75rem] font-medium tracking-wide text-[var(--text-secondary)] uppercase">
            {{ props.label }}
        </p>
        <p class="tabular mt-1 text-2xl leading-tight font-semibold">{{ props.value }}</p>
        <p v-if="props.context" class="mt-0.5 text-[0.8125rem] text-[var(--text-muted)]">
            {{ props.context }}
        </p>
    </div>
</template>
