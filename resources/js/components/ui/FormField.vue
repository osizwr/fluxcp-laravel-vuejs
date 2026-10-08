<script setup lang="ts">
import { computed, useId, useSlots } from 'vue'

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

/*
 * A hint can be a sentence or it can be something richer -- the password
 * checklist, say. Either way the field has to point `aria-describedby` at it,
 * so presence is what matters here, not which of the two it is.
 */
const slots = useSlots()
const described = computed(() => Boolean(props.hint) || Boolean(slots.hint))
</script>

<template>
    <!-- `relative`, so a floating hint positions against the field. -->
    <div class="field-group relative">
        <label :for="id" class="field-label">{{ props.label }}</label>

        <slot
            :id="id"
            :invalid="Boolean(props.error)"
            :described-by="
                [props.error ? errorId : null, described ? hintId : null]
                    .filter(Boolean)
                    .join(' ') || undefined
            "
        />

        <slot :id="hintId" name="hint">
            <p
                v-if="props.hint && !props.error"
                :id="hintId"
                class="mt-1 text-[0.8125rem] text-[var(--text-muted)]"
            >
                {{ props.hint }}
            </p>
        </slot>

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
