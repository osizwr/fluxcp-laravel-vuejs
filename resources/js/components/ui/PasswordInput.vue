<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useTranslation } from '../../i18n'

/**
 * A password field with a reveal control.
 *
 * Typing a password you cannot see is the one case where masking costs more
 * than it protects: nobody is reading over your shoulder on a laptop at home,
 * and the usual alternative -- a confirmation field to catch the typo you
 * could not see -- costs more keystrokes than letting you look.
 *
 * Reveal is per field and resets on every mount, so a revealed password never
 * survives a navigation or outlives the form it was typed into.
 *
 * `inheritAttrs` is off so that everything the caller passes -- the generated
 * id, name, autocomplete, length limits, `aria-describedby` from FormField --
 * lands on the input rather than on the positioning wrapper, where a screen
 * reader would never find it.
 */
defineOptions({ inheritAttrs: false })

const model = defineModel<string>({ required: true })

const { t } = useTranslation()

/*
 * `:value` and `@input` rather than `v-model`, because the type is bound.
 * Vue refuses `v-model` on an input whose `type` is dynamic -- it cannot know
 * at compile time which value binding to generate -- and this field's whole
 * job is to change its type.
 */
const revealed = ref(false)

/*
 * The reveal only appears once there is something to reveal.
 *
 * An empty field has nothing to show, so the control would be an icon that
 * does nothing -- and on a sign-in form it is the first thing the eye lands on
 * after the label, competing with the field it is supposed to serve.
 *
 * The space it will occupy is held open from the start, by the input's own
 * padding, so the control appears beside the text rather than shoving it.
 */
const hasValue = computed(() => model.value !== '')

/*
 * Emptying the field puts it back to masked.
 *
 * Not tidiness: the control that would turn reveal off has just disappeared
 * along with the text, so leaving the mode on strands the field in a state
 * with no way out of it -- and the next thing typed would then be in clear
 * text, which is not what clearing a password field implies.
 */
watch(hasValue, (present) => {
    if (!present) {
        revealed.value = false
    }
})

function onInput(event: Event): void {
    model.value = (event.target as HTMLInputElement).value
}
</script>

<template>
    <div class="field-password">
        <input
            v-bind="$attrs"
            class="field-input field-password__input"
            :type="revealed ? 'text' : 'password'"
            :value="model"
            @input="onInput"
        />

        <!--
            Labelled rather than titled, and `aria-pressed` so the control
            announces which state it is in instead of only what it will do.

            `tabindex` is deliberately left alone: the reveal is reachable by
            keyboard, because somebody who cannot see the field they are typing
            into is exactly who needs it.
        -->
        <button
            v-if="hasValue"
            type="button"
            class="field-password__toggle"
            :aria-label="revealed ? t('auth.hidePassword') : t('auth.showPassword')"
            :aria-pressed="revealed"
            @click="revealed = !revealed"
        >
            <svg
                class="size-5"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M1.8 10S5 4.9 10 4.9 18.2 10 18.2 10 15 15.1 10 15.1 1.8 10 1.8 10Z" />
                <circle cx="10" cy="10" r="2.4" />
                <path v-if="!revealed" d="M3.6 3.6 16.4 16.4" />
            </svg>
        </button>
    </div>
</template>
