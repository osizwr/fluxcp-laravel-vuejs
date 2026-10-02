<script setup lang="ts">
const props = defineProps<{ title: string; description?: string }>()
</script>

<template>
    <header class="mb-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ props.title }}</h1>
                <p v-if="props.description" class="mt-0.5 text-sm text-[var(--text-secondary)]">
                    {{ props.description }}
                </p>
            </div>

            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
