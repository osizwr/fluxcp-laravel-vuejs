<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useTranslation } from '../../i18n'

/**
 * A code typed one digit per box.
 *
 * Six boxes rather than one field, because a code arrives as six separate
 * characters and is checked against the e-mail one at a time. The boxes make
 * the position obvious, so a misread digit is found by looking rather than by
 * counting along a string.
 *
 * The cost of splitting a value across six inputs is that every behaviour a
 * single field gives for free has to be put back, and leaving any of them out
 * is what makes this pattern frustrating:
 *
 *   typing      moves forward on its own, including when a box is overwritten
 *   backspace   clears, then steps back, so holding it empties the row
 *   arrows      move without changing anything
 *   paste       fills from wherever it was pasted, so a copied code works
 *   autofill    the OS fills the first box with the whole code, which is
 *               spread across the rest rather than truncated
 *
 * The value is a plain string to its parent: nothing outside knows it is six
 * inputs.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string
        length?: number
        invalid?: boolean
        disabled?: boolean
    }>(),
    { length: 6, invalid: false, disabled: false },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const { t } = useTranslation()

const boxes = ref<HTMLInputElement[]>([])

const digits = computed(() =>
    Array.from({ length: props.length }, (_, i) => props.modelValue[i] ?? ''),
)

function publish(next: string[]): void {
    emit('update:modelValue', next.join('').slice(0, props.length))
}

function focusBox(index: number): void {
    void nextTick(() => boxes.value[Math.max(0, Math.min(index, props.length - 1))]?.focus())
}

/**
 * One box changed.
 *
 * Anything longer than a digit is treated as a paste into this position --
 * which is what an OS autofilling the whole code into the first box looks
 * like, and what a visitor pasting from their mail app does.
 */
function onInput(index: number, event: Event): void {
    const element = event.target as HTMLInputElement
    const typed = element.value.replace(/\D/g, '')

    const next = [...digits.value]

    if (typed.length <= 1) {
        /*
         * A keystroke that was not a digit leaves the box as it was.
         *
         * Focusing a box selects it, so typing over a filled one replaces its
         * contents -- which means a letter, or any dead key an IME passes
         * through, would otherwise empty a digit that was already right.
         * Emptying a box is what Backspace is for, and that arrives here with
         * nothing in the element rather than with something unusable in it.
         */
        const digit = typed === '' && element.value !== '' ? digits.value[index] : typed

        next[index] = digit
        element.value = digit
        publish(next)

        if (digit !== '' && typed !== '') {
            focusBox(index + 1)
        }

        return
    }

    for (let i = 0; i < typed.length && index + i < props.length; i++) {
        next[index + i] = typed[i]
    }

    element.value = next[index]
    publish(next)
    focusBox(index + typed.length)
}

function onKeydown(index: number, event: KeyboardEvent): void {
    if (event.key === 'Backspace') {
        /*
         * An empty box steps back and clears the one before it, so holding
         * backspace walks the row rather than stopping at the first gap.
         */
        if (digits.value[index] === '') {
            event.preventDefault()

            const next = [...digits.value]
            next[Math.max(0, index - 1)] = ''
            publish(next)
            focusBox(index - 1)
        }

        return
    }

    if (event.key === 'ArrowLeft') {
        event.preventDefault()
        focusBox(index - 1)
    } else if (event.key === 'ArrowRight') {
        event.preventDefault()
        focusBox(index + 1)
    }
}

function onPaste(index: number, event: ClipboardEvent): void {
    const pasted = (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '')

    if (pasted === '') {
        return
    }

    event.preventDefault()

    const next = [...digits.value]

    for (let i = 0; i < pasted.length && index + i < props.length; i++) {
        next[index + i] = pasted[i]
    }

    publish(next)
    focusBox(index + pasted.length)
}

/** Selecting on focus means typing replaces rather than appending. */
function onFocus(event: FocusEvent): void {
    ;(event.target as HTMLInputElement).select()
}

defineExpose({ focus: () => focusBox(0) })
</script>

<template>
    <div class="code-input" role="group" :style="{ '--code-count': props.length }">
        <input
            v-for="(digit, index) in digits"
            :key="index"
            :ref="
                (el) => {
                    if (el) boxes[index] = el as HTMLInputElement
                }
            "
            class="code-input__box"
            :class="{
                'code-input__box--filled': digit !== '',
                'code-input__box--invalid': props.invalid,
            }"
            type="text"
            size="1"
            inputmode="numeric"
            :autocomplete="index === 0 ? 'one-time-code' : 'off'"
            maxlength="6"
            :value="digit"
            :disabled="props.disabled"
            :aria-label="t('register.otpDigit', { position: index + 1, total: props.length })"
            :aria-invalid="props.invalid || undefined"
            @input="onInput(index, $event)"
            @keydown="onKeydown(index, $event)"
            @paste="onPaste(index, $event)"
            @focus="onFocus"
        />
    </div>
</template>
