<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import ArtPlaceholder from '../components/ArtPlaceholder.vue'
import CrowMark from '../components/CrowMark.vue'

/**
 * Yatagarasu — the legend.
 *
 * A theme-only block, and the one place on the page that is purely
 * atmosphere: full-bleed artwork, three short lines over it, and nothing to
 * read or click but one link onward.
 *
 * The three lines are the theme's own writing, which is why they are here and
 * not in configuration — they are art direction, the way the layout is, and a
 * server that wants its own words replaces this block or writes a page.
 *
 * `Three wings, one destiny` is the motif: Yatagarasu is the three-legged
 * crow, and the section it names is the only one that says so outright.
 */
const { title, game } = useGame()
</script>

<template>
    <section class="yata-lore" aria-labelledby="yata-lore-title">
        <!-- Artwork, full bleed. -->
        <div class="yata-lore__art">
            <ArtPlaceholder
                path="/images/world/yatagarasu-lore.webp"
                alt="A moonlit shrine above the clouds, three crows circling its gate"
                ratio="h-full w-full"
                anchor="corner"
            />
        </div>

        <div class="yata-lore__veil" aria-hidden="true" />

        <div class="yata-lore__inner">
            <CrowMark :size="40" class="text-[var(--color-accent-500)]" :label="`The mark of ${title}`" />

            <p class="yata-eyebrow mt-7"><span class="yata-eyebrow__text">The legend</span></p>

            <h2 id="yata-lore-title" class="yata-lore__title">The Legend of {{ title }}</h2>

            <!--
                Set as three lines rather than a paragraph. The break is the
                point: it is a verse, and a wrapped sentence is not.
            -->
            <p class="yata-lore__verse">
                <span>Three wings.</span>
                <span>One destiny.</span>
                <span>A world waiting to remember your name.</span>
            </p>

            <p class="mt-10">
                <a
                    v-if="game.links.forum"
                    :href="game.links.forum"
                    class="yata-btn yata-btn--secondary"
                    rel="noreferrer noopener"
                >
                    Read the chronicles
                </a>
                <RouterLink v-else to="/who-is-online" class="yata-btn yata-btn--secondary">
                    See who walks the realm
                </RouterLink>
            </p>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-lore {
    position: relative;
    overflow: hidden;
    isolation: isolate;
}

:root[data-theme-slug='yatagarasu'] .yata-lore__art,
:root[data-theme-slug='yatagarasu'] .yata-lore__art figure {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
}

/*
 * Heavier than the hero's veil. This section is read, not looked at, and the
 * verse is the only thing on it — the artwork is there to give the words
 * somewhere to be.
 */
:root[data-theme-slug='yatagarasu'] .yata-lore__veil {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            180deg,
            var(--surface-page) 0%,
            color-mix(in oklab, var(--yata-void) 72%, transparent) 22%,
            color-mix(in oklab, var(--yata-void) 72%, transparent) 78%,
            var(--surface-page) 100%
        ),
        radial-gradient(90% 80% at 50% 50%, transparent 30%, rgb(139 0 0 / 28%) 100%);
}

:root[data-theme-slug='yatagarasu'] .yata-lore__inner {
    position: relative;
    max-width: 48rem;
    margin-inline: auto;
    padding: 7rem 1rem;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-lore__inner {
        padding-block: 10rem;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-lore__title {
    margin-top: 1rem;
    font-family: var(--yata-font-deco);
    font-size: clamp(1.75rem, 5vw, 2.75rem);
    font-weight: 700;
    letter-spacing: 0.04em;
    color: var(--yata-ivory);
    text-wrap: balance;
}

:root[data-theme-slug='yatagarasu'] .yata-lore__verse {
    margin-top: 2.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    font-family: var(--yata-font-body);
    font-style: italic;
    font-size: clamp(1.25rem, 2.8vw, 1.75rem);
    font-weight: 400;
    line-height: 1.4;
    letter-spacing: 0.06em;
    color: color-mix(in oklab, var(--yata-ivory) 88%, transparent);
    text-wrap: balance;
}

/* The last line is the one that addresses the reader, so it carries the gold. */
:root[data-theme-slug='yatagarasu'] .yata-lore__verse span:last-child {
    color: var(--color-accent-300);
}
</style>
