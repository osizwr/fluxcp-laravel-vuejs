<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useTranslation } from '../i18n'

/**
 * The page a visitor lands on when the router matches nothing.
 *
 * Full-bleed artwork and nothing else. The route asks for the `bare` layout,
 * so there is no navbar, no status strip and no footer behind this -- a dead
 * end is not a place to browse from, and the usual furniture would be
 * answering a question the visitor did not ask.
 *
 * It sends them home on its own after a short wait, because a dead end is
 * usually reached from a stale link somebody else wrote -- a forum post, a
 * Discord message, a bookmark from before a rename -- and the visitor has no
 * idea what the right address was. Leaving them to find the way back is
 * leaving them to guess.
 *
 * The wait is cancellable, and that is not decoration: a page that moves
 * under somebody who is still reading it fails WCAG 2.2.1 unless they can
 * turn the timer off. It also stops the redirect from racing a visitor who is
 * halfway through copying the broken URL out of the address bar to report it.
 */
const { t } = useTranslation()
const router = useRouter()

/** Long enough to read the page, short enough not to feel stuck. */
const SECONDS = 10

const remaining = ref(SECONDS)
const cancelled = ref(false)

let timer: ReturnType<typeof setInterval> | undefined

function stop(): void {
    if (timer !== undefined) {
        clearInterval(timer)
        timer = undefined
    }
}

function stay(): void {
    stop()
    cancelled.value = true
}

onMounted(() => {
    timer = setInterval(() => {
        remaining.value -= 1

        if (remaining.value > 0) {
            return
        }

        stop()

        /*
         * Replace rather than push: pushing leaves the dead address in the
         * history, so the first press of Back returns to this page, which
         * counts down and throws the visitor forward again. That is a trap,
         * not navigation.
         */
        void router.replace('/')
    }, 1000)
})

/* Navigating away by hand unmounts the page, which is what cancels the
 * redirect for a visitor who did not wait. */
onBeforeUnmount(stop)
</script>

<template>
    <div class="error-screen">
        <!--
            Edge to edge, which means cropped: the drawing is 16:9 and almost
            no screen is, so one axis always overflows.

            The picture carries the message, so the text of it is below for
            anybody who cannot see the picture, and only for them. `alt` is
            the wrong place: that is a heading and a sentence, not a label.
        -->
        <img class="error-screen__art" src="/images/errors/404.webp" alt="" aria-hidden="true" />

        <div class="sr-only">
            <h1>{{ t('notFound.title') }}</h1>
            <p>{{ t('notFound.body') }}</p>
        </div>

        <!--
            Everything that takes a click, on a scrim at the foot of the
            screen. A scrim rather than nothing, because the artwork behind it
            is bright in places and dark in others and white text alone would
            be unreadable over half of them.
        -->
        <div class="error-screen__controls">
            <!--
                Not a live region: the number changes every second, and a live
                region here would read the whole sentence out ten times over.
            -->
            <p v-if="!cancelled" class="error-screen__countdown">
                {{ t('notFound.countdown', { count: remaining }) }}
            </p>

            <!--
                Mounted empty and filled on cancel, rather than inserted at
                that moment: a live region has to be in the document before
                its contents change for the change to be announced.
            -->
            <p class="sr-only" role="status">{{ cancelled ? t('notFound.stayed') : '' }}</p>

            <div class="error-screen__actions">
                <RouterLink to="/" class="error-screen__button error-screen__button--primary">
                    {{ t('notFound.back') }}
                </RouterLink>

                <button
                    v-if="!cancelled"
                    type="button"
                    class="error-screen__button error-screen__button--ghost"
                    @click="stay"
                >
                    {{ t('notFound.stay') }}
                </button>
            </div>
        </div>
    </div>
</template>
