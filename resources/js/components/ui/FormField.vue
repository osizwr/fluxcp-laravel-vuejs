<script setup lang="ts">
import { useId } from 'vue'

/**
 * A labelled form control.
 *
 * The label is tied to the input with a generated id, and an error message is
 * announced and linked with aria-describedby, so a screen reader reaches it
 * rather than only sighted users seeing red text.
 */
const props = defineProps<{
    label: string
    error?: string
    hint?: string
}>()

const id = useId()
const errorId = `${id}-error`
const hintId = `${id}-hint`
</script>

<template>
    <div>
        <label :for="id" class="field-label">{{ props.label }}</label>

        <slot
            :id="id"
            :invalid="Boolean(props.error)"
            :described-by="
                [props.error ? errorId : null, props.hint ? hintId : null]
                    .filter(Boolean)
                    .join(' ') || undefined
            "
        />

        <p
            v-if="props.hint && !props.error"
            :id="hintId"
            class="mt-1 text-[0.8125rem] text-[var(--text-muted)]"
        >
            {{ props.hint }}
        </p>

        <p
            v-if="props.error"
            :id="errorId"
            class="mt-1 text-[0.8125rem] text-[var(--color-down)]"
            role="alert"
        >
            {{ props.error }}
        </p>
    </div>
</template>
