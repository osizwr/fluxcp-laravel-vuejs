<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAnnouncement, useNewsData } from '@/blocks/data'
import { useTranslation } from '@/i18n'

const { t } = useTranslation()

/**
 * Skyward — the news carousel.
 *
 * A second floating card below the masthead: a standing NEWS badge, then the
 * server's own headlines taking turns in one place.
 *
 * A carousel rather than a marquee, which is a reading decision more than a
 * visual one. A line of text sliding past has to be tracked to be read, and a
 * reader who glances away loses their place in it; a headline that sits still
 * for a few seconds is simply read. The cost is that only one is visible at a
 * time, which is why there is a counter and a pair of controls -- otherwise
 * nothing on screen says there are five more.
 *
 * It takes the latest dispatches first, because a headline somebody wrote is
 * worth more than a standing notice, and falls back to the operator's
 * announcement when there are none. With neither it renders nothing -- an
 * empty bar is a placeholder, and a placeholder takes up space while saying
 * nothing. The theme's layout measures the shell rather than assuming its
 * height, so the hero closes the gap by itself on a server with no news.
 *
 * Both sources come from composables. The block cannot fetch, which is what
 * lets the carousel be swapped for a plain bar by changing themes.
 */
interface TickerItem {
    key: string
    text: string
    href: string | null
    label: string
}

/** How long a headline holds before the next one takes its place. */
const DWELL = 5500

const { announcement } = useAnnouncement()
const news = useNewsData(6)

const items = computed<TickerItem[]>(() => {
    const headlines = news.value.articles
        .filter((article) => article.title !== '')
        .map((article) => ({
            key: `article-${article.id}`,
            text: article.title,
            href: article.link,
            label: t('news.read'),
        }))

    if (headlines.length > 0) {
        return headlines
    }

    return announcement.value === null
        ? []
        : [
              {
                  key: `announcement-${announcement.value.id}`,
                  text: announcement.value.message,
                  href: announcement.value.url,
                  label: announcement.value.label ?? t('news.readMore'),
              },
          ]
})

/*
 * One headline is not a carousel. It is the common case on a server with no
 * news and one standing announcement, and giving it a counter reading "1 of 1"
 * and two controls that do nothing would be furniture around a single
 * sentence.
 *
 * So it drifts across the bar instead of taking turns -- the motion a news
 * strip is expected to have, obtained by moving the headline rather than by
 * changing it. That also lets it keep the announcement's own urgency: a
 * maintenance notice is an alert, and wrapping it in a carousel region would
 * bury that.
 */
const isCarousel = computed(() => items.value.length > 1)

const index = ref(0)
const direction = ref<'next' | 'prev'>('next')

/** The visitor's own pause, via the control. Survives the pointer leaving. */
const playing = ref(true)

/** A pointer resting on the bar, or focus inside it. Both mean "being read". */
const engaged = ref(false)

/*
 * Motion is a preference the browser already knows. Honoured in script as well
 * as in the stylesheet because the stylesheet can only stop the slide looking
 * like motion -- it cannot stop the headline being replaced under someone who
 * asked for things to hold still. Here it stops the advance itself, leaving
 * the controls to move through the news at the reader's pace.
 */
const reducedMotion = ref(false)
let motionQuery: MediaQueryList | null = null

function onMotionChange(event: MediaQueryListEvent): void {
    reducedMotion.value = event.matches
}

/** Whether the carousel should currently be advancing by itself. */
const rotating = computed(
    () => isCarousel.value && playing.value && !engaged.value && !reducedMotion.value,
)

const active = computed<TickerItem | null>(() => items.value[index.value] ?? null)

function go(delta: number): void {
    const count = items.value.length

    if (count === 0) {
        return
    }

    direction.value = delta >= 0 ? 'next' : 'prev'
    index.value = (index.value + delta + count) % count
}

let timer: number | null = null

function stop(): void {
    if (timer !== null) {
        window.clearInterval(timer)
        timer = null
    }
}

function start(): void {
    stop()
    timer = window.setInterval(() => go(1), DWELL)
}

/**
 * Pressing a control also restarts the dwell.
 *
 * Without it the pending tick survives the press, so a headline chosen by hand
 * can be replaced a moment later by the timer -- which reads as the control
 * having been ignored.
 */
function step(delta: number): void {
    go(delta)

    if (rotating.value) {
        start()
    }
}

watch(rotating, (on) => (on ? start() : stop()))

/*
 * News arrives after the first paint, so the list this is indexing into can
 * grow or shrink underneath it. Clamping here rather than in `active` keeps the
 * counter and the slide telling the same story.
 */
watch(items, (next) => {
    if (index.value > next.length - 1) {
        index.value = 0
    }
})

onMounted(() => {
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
    reducedMotion.value = motionQuery.matches
    motionQuery.addEventListener('change', onMotionChange)

    if (rotating.value) {
        start()
    }
})

onBeforeUnmount(() => {
    stop()
    motionQuery?.removeEventListener('change', onMotionChange)
})
</script>

<template>
    <!--
        A single notice keeps the announcement's own urgency and needs none of
        the carousel's machinery -- there is nothing to take turns with. It
        drifts instead, so the bar still reads as live rather than as switched
        off, and keeps `role="alert"` for a maintenance notice, which a
        carousel region would bury.
    -->
    <div
        v-if="items.length === 1 && active"
        class="sky-ticker"
        :role="announcement?.tone === 'maintenance' ? 'alert' : 'status'"
    >
        <p class="sky-ticker__label">
            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    d="M16.5 3.2a1 1 0 00-1.56-.83L8.3 6.8H5a3 3 0 00-.4 5.97l1.2 4.02a1 1 0 001.9-.08l-.9-3.9h1.5l6.64 4.43a1 1 0 001.56-.83V3.2z"
                />
            </svg>
            <span class="sky-ticker__label-text">{{ t('news.badge') }}</span>
        </p>

        <div class="sky-ticker__window">
            <div class="sky-ticker__drift">
                <span>{{ active.text }}</span>
                <a
                    v-if="active.href"
                    :href="active.href"
                    class="sky-ticker__link"
                    rel="noreferrer noopener"
                >
                    {{ active.label }} <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </div>
    </div>

    <div
        v-else-if="isCarousel && active"
        class="sky-ticker"
        role="region"
        aria-roledescription="carousel"
        :aria-label="t('news.badge')"
        @mouseenter="engaged = true"
        @mouseleave="engaged = false"
        @focusin="engaged = true"
        @focusout="engaged = false"
    >
        <p class="sky-ticker__label">
            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    d="M16.5 3.2a1 1 0 00-1.56-.83L8.3 6.8H5a3 3 0 00-.4 5.97l1.2 4.02a1 1 0 001.9-.08l-.9-3.9h1.5l6.64 4.43a1 1 0 001.56-.83V3.2z"
                />
            </svg>
            <span class="sky-ticker__label-text">{{ t('news.badge') }}</span>
        </p>

        <!--
            Announced only while it is not advancing by itself. A live region
            that keeps changing every few seconds interrupts a screen reader
            mid-sentence, so the rotation stays silent and the headline is read
            out once the reader has stopped it -- by pausing, hovering, or
            tabbing in.
        -->
        <div class="sky-ticker__window" :aria-live="rotating ? 'off' : 'polite'">
            <Transition :name="`sky-slide-${direction}`">
                <p
                    :key="active.key"
                    class="sky-ticker__slide"
                    role="group"
                    aria-roledescription="slide"
                    :aria-label="`${index + 1} of ${items.length}`"
                >
                    <span class="sky-ticker__text">{{ active.text }}</span>
                    <a
                        v-if="active.href"
                        :href="active.href"
                        class="sky-ticker__link"
                        rel="noreferrer noopener"
                    >
                        {{ active.label }} <span aria-hidden="true">&rarr;</span>
                    </a>
                </p>
            </Transition>
        </div>

        <div class="sky-ticker__controls">
            <button
                type="button"
                class="sky-ticker__control sky-ticker__control--step"
                :aria-label="t('news.previous')"
                @click="step(-1)"
            >
                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        fill-rule="evenodd"
                        d="M12.79 5.23a.75.75 0 01-.02 1.06L9.31 10l3.46 3.71a.75.75 0 11-1.1 1.02l-3.95-4.25a.75.75 0 010-1.02l3.95-4.25a.75.75 0 011.06-.02z"
                        clip-rule="evenodd"
                    />
                </svg>
            </button>

            <span class="sky-ticker__count"> {{ index + 1 }}/{{ items.length }} </span>

            <button
                type="button"
                class="sky-ticker__control sky-ticker__control--step"
                :aria-label="t('news.next')"
                @click="step(1)"
            >
                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        fill-rule="evenodd"
                        d="M7.21 14.77a.75.75 0 01.02-1.06L10.69 10 7.23 6.29a.75.75 0 111.1-1.02l3.95 4.25a.75.75 0 010 1.02l-3.95 4.25a.75.75 0 01-1.06.02z"
                        clip-rule="evenodd"
                    />
                </svg>
            </button>

            <!--
                Auto-advancing content needs a stop that does not depend on
                holding a pointer still, which a hover pause does.
            -->
            <button
                type="button"
                class="sky-ticker__control"
                :aria-label="playing ? t('news.pause') : t('news.play')"
                :aria-pressed="!playing"
                @click="playing = !playing"
            >
                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        v-if="playing"
                        d="M6.5 4a1 1 0 00-1 1v10a1 1 0 002 0V5a1 1 0 00-1-1zm7 0a1 1 0 00-1 1v10a1 1 0 002 0V5a1 1 0 00-1-1z"
                    />
                    <path
                        v-else
                        d="M6.3 3.6a1 1 0 011.02.04l8 5a1 1 0 010 1.72l-8 5A1 1 0 015.8 14.5v-10a1 1 0 01.5-.87z"
                    />
                </svg>
            </button>
        </div>
    </div>
</template>
