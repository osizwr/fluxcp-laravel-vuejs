<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue'
import { useAnnouncement, useNewsData } from '@/blocks/data'
import { defaultNewsTag, newsTag } from '@/blocks/tags'
import { useTranslation } from '@/i18n'

const { t } = useTranslation()

/**
 * Skyward — the news crawl.
 *
 * A second floating card below the masthead: a standing NEWS badge, then the
 * server's own headlines crossing the strip, one at a time, in a loop.
 *
 * A crawl rather than a carousel, now that a server runs more than two or
 * three headlines. A carousel holds one headline still and hides the rest
 * behind a counter, which asks a visitor to stand and wait for news they
 * cannot see is coming; a headline that travels across and is followed by the
 * next says there is more without anybody having to operate anything, and the
 * strip reads as live rather than as a slideshow.
 *
 * One line at a time rather than all of them strung together: a single
 * sentence crossing is a thing to read, where a continuous ribbon of every
 * headline at once is a wall to scan, and the eye has nowhere to rest between
 * two of them. The cost of any moving text is that reading means tracking it,
 * which is what the hover and focus stops are for: a headline somebody has
 * stopped on, or tabbed into, holds where it is until they are done with it.
 *
 * It takes the latest dispatches first, because a headline somebody wrote is
 * worth more than a standing notice, and falls back to the operator's
 * announcement when there are none. With neither it renders nothing -- an
 * empty bar is a placeholder, and a placeholder takes up space while saying
 * nothing. The theme's layout measures the shell rather than assuming its
 * height, so the hero closes the gap by itself on a server with no news.
 *
 * Both sources come from composables. The block cannot fetch, which is what
 * lets the crawl be swapped for a plain bar by changing themes.
 */
interface TickerItem {
    key: string
    text: string
    href: string | null
    label: string
    /** The editor's own word for this post, or null for an untagged one. */
    tag: string | null
}

/**
 * How fast a headline travels, in pixels a second.
 *
 * Held as a speed rather than as a duration so that a long headline and a
 * short one read at the same pace, and so that the bar reads the same on a
 * phone as on a wide screen -- a fixed duration per crossing would make the
 * long headline sprint, the short one dawdle, and the wide screen crawl.
 *
 * The number is set by how long a headline is worth looking at rather than by
 * how fast it can be read: a word crosses a desktop strip in something like
 * half a minute, which is slow enough to be read without being tracked, and a
 * glance that misses one catches the next.
 */
const SPEED = 80

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
            tag: article.tag,
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
                  /* An operator's standing notice is not an article and has
                   * nowhere to carry a tag, so it wears the standing badge. */
                  tag: null,
              },
          ]
})

/*
 * A lone headline has nothing to be followed by, so it crosses on repeat and
 * needs none of the machinery below -- no turn to take and nothing to hand
 * the strip to. Keeping it a separate case is also what lets it hold the
 * announcement's own urgency: a maintenance notice is an alert, and wrapping
 * it in a news region would bury that.
 */
const isCrawl = computed(() => items.value.length > 1)

/*
 * Motion is a preference the browser already knows. A reader who asked for
 * stillness gets the headlines as a list they scroll instead of a line that
 * travels -- same news, no movement -- so this mirror of the query decides
 * which of the two the strip renders.
 */
const reducedMotion = ref(false)
let motionQuery: MediaQueryList | null = null

function onMotionChange(event: MediaQueryListEvent): void {
    reducedMotion.value = event.matches
}

const index = ref(0)
const active = computed<TickerItem | null>(() => items.value[index.value] ?? null)

/*
 * The badge is the crossing headline's own tag -- green NEWS, violet AUCTION
 * -- rather than one standing word for the whole strip. Which is the point of
 * tagging posts at all: the badge says what kind of thing is coming past
 * before the sentence has been read.
 *
 * An untagged post, and the operator's standing announcement, fall back to the
 * plain News badge. That fallback is asked for by name rather than being what
 * `newsTag` returns for an empty tag, because elsewhere -- the news list --
 * an untagged article should carry no pill at all.
 */
const tag = computed(() => newsTag(active.value?.tag))
const badge = computed(() => tag.value ?? defaultNewsTag(t('news.badge')))

const badgeStyle = computed(() => ({
    '--sky-badge-bg': `var(--tag-${badge.value.tone}-bg)`,
    '--sky-badge-ink': `var(--tag-${badge.value.tone}-ink)`,
}))

/**
 * The next headline takes the strip.
 *
 * Driven by the animation finishing a lap rather than by a timer, which is
 * what keeps the two in step: a timer would hand the strip to the next
 * headline while the last one was still halfway across it, and a paused
 * animation would go on being handed headlines it is not showing.
 */
function advance(): void {
    const count = items.value.length

    if (count > 0) {
        index.value = (index.value + 1) % count
    }
}

/*
 * News arrives after the first paint, so the list this is indexing into can
 * grow or shrink underneath it.
 */
watch(items, (next) => {
    if (index.value > next.length - 1) {
        index.value = 0
    }
})

/*
 * A crossing is the strip's width plus the headline's own -- in from just off
 * the right edge, out past the left -- and the duration is that distance at
 * the speed above. Both widths have to be taken from the page: the headline
 * is whatever the operator wrote in whatever font arrived, and the strip is
 * whatever the viewport left it. They are watched rather than read once, so a
 * font landing late or a window dragged narrower re-times the crossing rather
 * than leaving the pace wrong for the rest of the session.
 */
const viewport = useTemplateRef<HTMLElement>('viewport')
const line = useTemplateRef<HTMLElement>('line')

const viewportWidth = ref(0)
const lineWidth = ref(0)

const crawlStyle = computed(() => {
    if (lineWidth.value === 0) {
        return undefined
    }

    return { '--sky-crawl-duration': `${(viewportWidth.value + lineWidth.value) / SPEED}s` }
})

function measure(): void {
    viewportWidth.value = viewport.value?.getBoundingClientRect().width ?? 0
    lineWidth.value = line.value?.getBoundingClientRect().width ?? 0
}

let observer: ResizeObserver | null = null

onMounted(() => {
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
    reducedMotion.value = motionQuery.matches
    motionQuery.addEventListener('change', onMotionChange)

    observer = new ResizeObserver(measure)

    if (viewport.value) {
        observer.observe(viewport.value)
    }

    measure()
})

/*
 * The line only exists once there is news to put in it, which arrives after
 * the first paint, and it is replaced outright when the reader's motion
 * preference changes. Re-pointing the observer here rather than in `measure`
 * keeps it watching whichever element is currently the headline.
 */
watch(line, (element, previous) => {
    if (previous) {
        observer?.unobserve(previous)
    }

    if (element) {
        observer?.observe(element)
    }

    measure()
})

onBeforeUnmount(() => {
    observer?.disconnect()
    motionQuery?.removeEventListener('change', onMotionChange)
})
</script>

<template>
    <!--
        One notice, crossing on repeat. It keeps `role="alert"` for a
        maintenance notice, which a news region would bury.
    -->
    <div
        v-if="items.length === 1 && items[0]"
        class="sky-ticker"
        :role="announcement?.tone === 'maintenance' ? 'alert' : 'status'"
    >
        <!--
            The mark is decoration: it repeats the word beside it rather than
            adding to it, so it is hidden from assistive technology. A tag this
            panel has no mark for keeps the megaphone, which is what stops the
            badge collapsing to nothing where the word is hidden on a phone.
        -->
        <p
            class="sky-ticker__label"
            :class="tag === null ? 'sky-ticker__label--standing' : ''"
            :style="badgeStyle"
        >
            <span v-if="badge.icon" class="sky-ticker__label-icon" aria-hidden="true">
                {{ badge.icon }}
            </span>

            <svg v-else class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    d="M16.5 3.2a1 1 0 00-1.56-.83L8.3 6.8H5a3 3 0 00-.4 5.97l1.2 4.02a1 1 0 001.9-.08l-.9-3.9h1.5l6.64 4.43a1 1 0 001.56-.83V3.2z"
                />
            </svg>

            <span class="sky-ticker__label-text">{{ badge.label }}</span>
        </p>

        <div ref="viewport" class="sky-ticker__window">
            <div ref="line" class="sky-ticker__drift sky-ticker__drift--timed" :style="crawlStyle">
                <span>{{ items[0].text }}</span>
                <a
                    v-if="items[0].href"
                    :href="items[0].href"
                    class="sky-ticker__link"
                    rel="noreferrer noopener"
                >
                    {{ items[0].label }} <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </div>
    </div>

    <div v-else-if="isCrawl" class="sky-ticker" role="region" :aria-label="t('news.badge')">
        <!--
            The mark is decoration: it repeats the word beside it rather than
            adding to it, so it is hidden from assistive technology. A tag this
            panel has no mark for keeps the megaphone, which is what stops the
            badge collapsing to nothing where the word is hidden on a phone.
        -->
        <p
            class="sky-ticker__label"
            :class="tag === null ? 'sky-ticker__label--standing' : ''"
            :style="badgeStyle"
        >
            <span v-if="badge.icon" class="sky-ticker__label-icon" aria-hidden="true">
                {{ badge.icon }}
            </span>

            <svg v-else class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    d="M16.5 3.2a1 1 0 00-1.56-.83L8.3 6.8H5a3 3 0 00-.4 5.97l1.2 4.02a1 1 0 001.9-.08l-.9-3.9h1.5l6.64 4.43a1 1 0 001.56-.83V3.2z"
                />
            </svg>

            <span class="sky-ticker__label-text">{{ badge.label }}</span>
        </p>

        <!--
            Focusable only where nothing moves. A reader who asked for reduced
            motion scrolls the strip instead of waiting for it, and a scroll
            box nobody can reach by keyboard is a scroll box half the readers
            cannot use.
        -->
        <div ref="viewport" class="sky-ticker__window" :tabindex="reducedMotion ? 0 : undefined">
            <!--
                Stillness asked for: every headline at once, as a list to
                scroll through. Nothing travels, so nothing would ever bring
                the second headline into view on its own.
            -->
            <ul v-if="reducedMotion" class="sky-ticker__list">
                <li v-for="item in items" :key="item.key" class="sky-ticker__item">
                    <span>{{ item.text }}</span>
                    <a
                        v-if="item.href"
                        :href="item.href"
                        class="sky-ticker__link"
                        rel="noreferrer noopener"
                    >
                        {{ item.label }} <span aria-hidden="true">&rarr;</span>
                    </a>
                </li>
            </ul>

            <!--
                Otherwise one headline has the strip to itself until it is off
                the far side, and the next one follows it on. Keyed on the
                item so the crossing restarts cleanly rather than picking the
                new headline up wherever the old one had got to.
            -->
            <div
                v-else-if="active"
                :key="active.key"
                ref="line"
                class="sky-ticker__drift sky-ticker__drift--timed"
                :style="crawlStyle"
                @animationiteration="advance"
            >
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
</template>
