<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useTranslation, type Locale } from '@/i18n'

/**
 * Skyward — the language picker.
 *
 * Each language is written in itself -- "Português", not "Portuguese" -- because
 * the person reading this list is looking for a language they understand, and
 * labelling it in the language they are trying to leave is no help to them.
 *
 * The list comes from the composable rather than from this file, so the picker
 * cannot offer a language the server would refuse to serve.
 *
 * Choosing one reloads the page; see the note in @/i18n for why.
 */
const { locale, locales, setLocale, t } = useTranslation()

const open = ref(false)
const root = ref<HTMLElement | null>(null)
const trigger = ref<HTMLElement | null>(null)

/**
 * The active language, named in itself.
 *
 * Falls back to the code if the active locale is somehow not in the list,
 * which beats rendering an empty button.
 */
const current = computed(
    () => locales.value.find((option) => option.code === locale.value)?.name ?? locale.value,
)

function choose(code: Locale): void {
    open.value = false
    setLocale(code)
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && open.value) {
        open.value = false
        trigger.value?.focus()
    }
}

function onPointerDown(event: Event): void {
    const target = event.target as Node | null

    if (open.value && target !== null && root.value?.contains(target) !== true) {
        open.value = false
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    document.addEventListener('pointerdown', onPointerDown, true)
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown)
    document.removeEventListener('pointerdown', onPointerDown, true)
})
</script>

<template>
    <div ref="root" class="sky-lang">
        <button
            ref="trigger"
            type="button"
            class="sky-lang__trigger"
            :aria-label="t('nav.language')"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="open = !open"
        >
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M10 1a9 9 0 100 18 9 9 0 000-18zM7.5 10c0-1.9.3-3.6.8-4.9.5-1.3 1.2-1.9 1.7-1.9s1.2.6 1.7 1.9c.5 1.3.8 3 .8 4.9s-.3 3.6-.8 4.9c-.5 1.3-1.2 1.9-1.7 1.9s-1.2-.6-1.7-1.9c-.5-1.3-.8-3-.8-4.9zM2.6 11h3.4c.1 1.8.4 3.4.9 4.7A7.5 7.5 0 012.6 11zm0-2a7.5 7.5 0 014.3-4.7c-.5 1.3-.8 2.9-.9 4.7H2.6zm11.4 0c-.1-1.8-.4-3.4-.9-4.7A7.5 7.5 0 0117.4 9H14zm3.4 2a7.5 7.5 0 01-4.3 4.7c.5-1.3.8-2.9.9-4.7h3.4z"
                    clip-rule="evenodd"
                />
            </svg>
            <span>{{ current }}</span>
        </button>

        <Transition name="sky-sheet">
            <ul v-if="open" class="sky-lang__menu" role="menu">
                <li v-for="option in locales" :key="option.code" role="none">
                    <button
                        type="button"
                        role="menuitemradio"
                        :aria-checked="option.code === locale"
                        class="sky-lang__option"
                        :class="{ 'sky-lang__option--active': option.code === locale }"
                        @click="choose(option.code)"
                    >
                        <span>{{ option.name }}</span>
                        <svg
                            v-if="option.code === locale"
                            class="size-3.5 shrink-0"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0l-3.5-3.5a1 1 0 111.4-1.4l2.8 2.8 6.8-6.8a1 1 0 011.4 0z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                </li>
            </ul>
        </Transition>
    </div>
</template>
