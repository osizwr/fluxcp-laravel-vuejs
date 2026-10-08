<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { preloadState } from '@/composables/usePreload'
import { useGame } from '@/composables/useGame'
import GameMark from './GameMark.vue'
import { useTranslation } from '@/i18n'

const { t } = useTranslation()

/**
 * Skyward — the gate.
 *
 * The landing page is a full viewport of video. Rendered without it, the page
 * appears instantly with a hole where its subject should be, and the artwork
 * drops in several seconds later over the top of whatever the visitor had
 * started reading. This holds the page back until the media is here.
 *
 * A gate is only tolerable if it is honest about the wait, which is why the
 * figures are real: the percentage and both sizes come from counting bytes off
 * the wire, not from a timer. A visitor on a slow connection can watch the
 * number climb and tell the difference between "slow" and "broken" -- which is
 * the whole reason to show a number at all.
 *
 * This component contains no network code and could not: a test fails if a
 * theme file so much as mentions one. It declares a manifest and draws the
 * result; `usePreload` in core does the work. The manifest is the theme's
 * business because the theme is what decided to build a page around a video.
 */
const { game, title } = useGame()

/*
 * Reads the preload; it does not start one. The layout owns the manifest, so
 * that whether there is anything to wait for is settled before this mounts.
 */
const { total, percent, settled, loadedLabel, totalLabel, failures } = preloadState()

/*
 * Kept in the DOM for the length of the fade, then dropped. An overlay that
 * lingers at zero opacity still covers the page for a pointer and still holds
 * focusable children, so leaving it mounted would make the site look loaded and
 * refuse to be clicked.
 *
 * Seeded from `settled` rather than from `false`, which is what makes the gate
 * a once-per-page-load event. Only the landing page uses this shell, so leaving
 * it for the rankings and coming back remounts this component -- and a watcher
 * alone would never fire, because by then the preload has long since settled
 * and will not change again. The gate would mount over a site that was already
 * loaded and stay there.
 */
const dismissed = ref(settled.value)

/*
 * A brief hold so the bar is seen to reach 100% rather than vanishing at 99,
 * then the gate goes. It must start clearing well before the page behind it has
 * finished fading up -- otherwise the whole fade happens underneath an opaque
 * overlay and the only thing anyone sees is the overlay disappearing.
 */
watch(settled, (done) => {
    if (done) {
        window.setTimeout(() => (dismissed.value = true), 150)
    }
})

/*
 * Until the first response's headers arrive there is no total, and a bar at 0%
 * with "0 MB / 0 MB" under it reads as stalled. The indeterminate state says
 * "connecting" instead, which is what is actually happening.
 */
const measuring = computed(() => total.value <= 0 && !settled.value)

const detail = computed(() => {
    if (measuring.value) {
        return t('loading.connecting')
    }

    if (failures.value.length > 0 && settled.value) {
        return t('loading.withoutPreload')
    }

    return `${loadedLabel.value} / ${totalLabel.value}`
})
</script>

<template>
    <Transition name="sky-gate">
        <div
            v-if="!dismissed"
            class="sky-gate"
            :class="{ 'sky-gate--done': settled }"
            role="progressbar"
            :aria-valuemin="0"
            :aria-valuemax="100"
            :aria-valuenow="measuring ? undefined : percent"
            :aria-valuetext="measuring ? t('loading.connecting') : `${percent}%, ${detail}`"
            :aria-label="`Loading ${title}`"
        >
            <div class="sky-gate__panel">
                <GameMark :size="game.logo ? 104 : 72" />

                <!-- A supplied logo already carries the name; see Navbar.vue. -->
                <p v-if="!game.logo" class="sky-gate__title">{{ title }}</p>

                <p class="sky-gate__percent">
                    <span v-if="measuring">&middot;&middot;&middot;</span>
                    <template v-else>{{ percent }}<span class="sky-gate__unit">%</span></template>
                </p>

                <div class="sky-gate__track">
                    <div
                        class="sky-gate__fill"
                        :class="{ 'sky-gate__fill--measuring': measuring }"
                        :style="measuring ? undefined : { width: `${percent}%` }"
                    />
                </div>

                <p class="sky-gate__detail tabular">{{ detail }}</p>
            </div>
        </div>
    </Transition>
</template>
