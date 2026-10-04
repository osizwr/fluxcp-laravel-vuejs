<script setup lang="ts">
import { computed } from 'vue'
import { useAnnouncement, useNewsData } from '@/blocks/data'

/**
 * Yatagarasu — the ticker.
 *
 * The thin band above everything, scrolling the server's own headlines.
 *
 * It takes the latest dispatches first, because a headline somebody wrote is
 * worth more than a standing notice, and falls back to the operator's
 * announcement when there are none. With neither it renders nothing — an
 * empty bar is a placeholder, and a placeholder takes up space while saying
 * nothing.
 *
 * Both sources come from composables. The block cannot fetch, which is what
 * lets the ticker be swapped for a plain bar by changing themes.
 *
 * The scroll is a CSS animation rather than a requestAnimationFrame loop
 * driving a transform through component state: it runs on the compositor
 * instead of re-rendering sixty times a second, and it stops by itself under
 * `prefers-reduced-motion` rather than needing to be told.
 */
const { announcement } = useAnnouncement()
const news = useNewsData(6)

const items = computed<string[]>(() => {
    const headlines = news.value.articles.map((article) => article.title).filter(Boolean)

    if (headlines.length > 0) {
        return headlines
    }

    return announcement.value ? [announcement.value.message] : []
})

/*
 * The strip is rendered twice and translated by exactly half its width, so the
 * second copy arrives where the first began and the loop has no seam. With one
 * copy the content would slide off and snap back.
 */
const marquee = computed(() => [...items.value, ...items.value])

/* Long enough that the pace is a drift rather than a crawl at either length. */
const duration = computed(() => `${Math.max(30, items.value.length * 12)}s`)
</script>

<template>
    <div
        v-if="items.length > 0"
        class="yata-ticker"
        :role="announcement?.tone === 'maintenance' ? 'alert' : 'status'"
    >
        <div class="yata-ticker__track" :style="{ animationDuration: duration }" aria-hidden="true">
            <span v-for="(item, index) in marquee" :key="index" class="yata-ticker__item">
                <span class="yata-ticker__mark" />
                {{ item }}
            </span>
        </div>

        <!--
            The same headlines once, unanimated, for a screen reader. The moving
            strip is hidden from the accessibility tree because it contains
            every headline twice.
        -->
        <p class="sr-only">{{ items.join('. ') }}</p>
    </div>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-ticker {
    position: sticky;
    top: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    height: var(--yata-ticker-height);
    overflow: hidden;
    background-color: var(--yata-blood);
}

:root[data-theme-slug='yatagarasu'] .yata-ticker__track {
    display: flex;
    flex-shrink: 0;
    gap: 4rem;
    padding-right: 4rem;
    white-space: nowrap;
    will-change: transform;
    animation-name: yata-ticker-scroll;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
}

@keyframes yata-ticker-scroll {
    from {
        translate: 0 0;
    }

    to {
        /* Exactly one copy's width, which is half the doubled strip. */
        translate: -50% 0;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-ticker__item {
    font-family: var(--font-display);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--yata-blood-ink);
}

/*
 * The separator between headlines. Drawn rather than set as a character:
 * the display face has no crossed-swords glyph, so the reference's mark
 * arrived as a missing-character box.
 */
:root[data-theme-slug='yatagarasu'] .yata-ticker__mark {
    display: inline-block;
    width: 0.3rem;
    height: 0.3rem;
    margin-right: 0.6rem;
    vertical-align: middle;
    rotate: 45deg;
    background-color: currentColor;
    opacity: 0.6;
}

/*
 * Motion is the whole point of a ticker, so without it the strip simply parks
 * at its start and reads as a static band of headlines. The overflow stays
 * hidden, which means only the first few are visible — the screen-reader copy
 * carries the rest.
 */
@media (prefers-reduced-motion: reduce) {
    :root[data-theme-slug='yatagarasu'] .yata-ticker__track {
        animation: none;
    }
}
</style>
