<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useHeroData } from '@/blocks/data'
import type { HeroProps } from '@/blocks/contracts'
import { useGame } from '@/composables/useGame'
import ArtPlaceholder from '../components/ArtPlaceholder.vue'
import CrowMark from '../components/CrowMark.vue'

/**
 * Yatagarasu — the hero.
 *
 * A full-height cinematic plate: key art behind, the emblem centred over it
 * with embers drifting past, and two controls beneath.
 *
 * Centred rather than asymmetric, which is the reference's arrangement: the
 * emblem is the subject and the artwork frames it, so anything off to one
 * side would be competing with the thing it is meant to present.
 *
 * Everything written here is configuration or live state. The name, tagline
 * and actions come from the operator's GAME_* settings through useHeroData();
 * no copy about the game is hardcoded in this file.
 */
const props = withDefaults(defineProps<HeroProps>(), { showStatus: true })

const hero = useHeroData(props)
const { game, title } = useGame()

/*
 * Embers around the emblem, and slow-turning stars further out. Positions are
 * fixed rather than random so the hero renders identically everywhere and two
 * visitors are looking at the same picture.
 */
const embers = [
    { left: '12%', bottom: '30%', size: 5, delay: '0s', duration: '2.4s', colour: '#c9993a' },
    { left: '22%', bottom: '45%', size: 4, delay: '0.6s', duration: '2.8s', colour: '#fff0a0' },
    { left: '38%', bottom: '55%', size: 6, delay: '1.1s', duration: '2.2s', colour: '#c9993a' },
    { left: '52%', bottom: '60%', size: 3, delay: '0.3s', duration: '3.0s', colour: '#e8d5a3' },
    { left: '65%', bottom: '50%', size: 5, delay: '0.9s', duration: '2.5s', colour: '#fff0a0' },
    { left: '78%', bottom: '38%', size: 4, delay: '1.5s', duration: '2.7s', colour: '#c9993a' },
    { left: '88%', bottom: '55%', size: 3, delay: '0.2s', duration: '2.3s', colour: '#e8d5a3' },
    { left: '30%', bottom: '70%', size: 4, delay: '1.8s', duration: '2.6s', colour: '#fff0a0' },
    { left: '72%', bottom: '68%', size: 5, delay: '0.7s', duration: '2.9s', colour: '#c9993a' },
    { left: '48%', bottom: '75%', size: 3, delay: '1.3s', duration: '2.1s', colour: '#e8d5a3' },
]
</script>

<template>
    <section class="yata-hero" aria-labelledby="yata-hero-title">
        <!-- Layer 1: the key art. -->
        <div class="yata-hero__art">
            <ArtPlaceholder
                path="/images/hero/yatagarasu-world.webp"
                alt="A vast Japanese-inspired fantasy kingdom at night, beneath a full moon"
                ratio="h-full w-full"
                anchor="corner"
                eager
            />
        </div>

        <!--
            Layer 2: the night. Three overlays rather than one scrim — a fall
            to black down the page, a narrowing from the sides, and a pool at
            the foot where the controls sit. A flat 50% wash over the whole
            image would be easier and would throw the artwork away.
        -->
        <div class="yata-hero__veil" aria-hidden="true" />

        <!--
            Layer 3: the flourishes. Two pieces of character art flanking the
            emblem, pinned to a capped stage rather than the viewport so that
            on a very wide monitor they keep framing the content instead of
            drifting into the corners.
        -->
        <div class="yata-hero__stage" aria-hidden="true">
            <div class="yata-hero__flourish yata-hero__flourish--left">
                <ArtPlaceholder
                    path="/images/hero/flourish-left.webp"
                    alt=""
                    ratio="aspect-[3/4] w-full"
                    decorative
                />
            </div>
            <div class="yata-hero__flourish yata-hero__flourish--right">
                <ArtPlaceholder
                    path="/images/hero/flourish-right.webp"
                    alt=""
                    ratio="aspect-[3/4] w-full"
                    decorative
                />
            </div>
        </div>

        <!-- Layer 4: the words. -->
        <div class="yata-hero__inner">
            <p class="yata-eyebrow yata-rise">
                <span class="yata-eyebrow__rule" />
                <span class="yata-eyebrow__text">Enter the realm</span>
                <span class="yata-eyebrow__rule" />
            </p>

            <!--
                The emblem. An operator's own logo where they have set one,
                and the mark and wordmark otherwise. The embers are positioned
                against this wrapper, so they drift past the emblem rather
                than across the whole section.
            -->
            <div class="yata-hero__emblem yata-rise">
                <img
                    v-if="game.logo"
                    :src="game.logo"
                    :alt="title"
                    class="yata-glow w-full object-contain"
                    decoding="async"
                />
                <div v-else class="yata-glow flex flex-col items-center">
                    <CrowMark :size="78" class="text-[var(--color-accent-500)]" />
                    <h1 id="yata-hero-title" class="yata-hero__title">{{ hero.title }}</h1>
                </div>

                <span
                    v-for="(ember, index) in embers"
                    :key="index"
                    class="yata-ember"
                    aria-hidden="true"
                    :style="{
                        left: ember.left,
                        bottom: ember.bottom,
                        width: `${ember.size}px`,
                        height: `${ember.size}px`,
                        backgroundColor: ember.colour,
                        boxShadow: `0 0 ${ember.size * 2}px ${ember.colour}`,
                        animationDelay: ember.delay,
                        animationDuration: ember.duration,
                    }"
                />
            </div>

            <!-- When a logo image stands in for the title, the name still has
                 to reach a screen reader and the document outline. -->
            <h1 v-if="game.logo" id="yata-hero-title" class="sr-only">{{ hero.title }}</h1>

            <p v-if="hero.description" class="yata-hero__lead yata-rise yata-rise-2">
                {{ hero.description }}
            </p>

            <p
                v-if="hero.subtitle && hero.subtitle.toLowerCase() !== hero.title.toLowerCase()"
                class="yata-hero__kicker yata-rise yata-rise-2"
            >
                {{ hero.subtitle }}
            </p>

            <div class="yata-hero__actions yata-rise yata-rise-3">
                <RouterLink
                    v-if="hero.primaryAction?.to"
                    :to="hero.primaryAction.to"
                    class="yata-btn yata-btn--primary"
                >
                    {{ hero.primaryAction.label }}
                </RouterLink>
                <a
                    v-else-if="hero.primaryAction?.href"
                    :href="hero.primaryAction.href"
                    class="yata-btn yata-btn--primary"
                    rel="noreferrer noopener"
                >
                    {{ hero.primaryAction.label }}
                </a>

                <RouterLink
                    v-if="hero.secondaryAction?.to"
                    :to="hero.secondaryAction.to"
                    class="yata-btn yata-btn--secondary"
                >
                    {{ hero.secondaryAction.label }}
                </RouterLink>
                <a
                    v-else-if="hero.secondaryAction?.href"
                    :href="hero.secondaryAction.href"
                    class="yata-btn yata-btn--secondary"
                    rel="noreferrer noopener"
                >
                    {{ hero.secondaryAction.label }}
                </a>
            </div>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-hero {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 88svh;
    overflow: hidden;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__art,
:root[data-theme-slug='yatagarasu'] .yata-hero__art :is(figure, img) {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__art figure {
    opacity: 0.7;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__veil {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            180deg,
            rgb(8 8 11 / 50%) 0%,
            rgb(8 8 11 / 30%) 45%,
            var(--surface-page) 100%
        ),
        linear-gradient(90deg, rgb(8 8 11 / 70%) 0%, transparent 50%, rgb(8 8 11 / 50%) 100%),
        radial-gradient(ellipse 70% 50% at 50% 80%, rgb(8 8 11 / 70%) 0%, transparent 70%);
}

/* ---- Flourishes --------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-hero__stage {
    position: absolute;
    inset: 0;
    z-index: 10;
    display: none;
    max-width: 120rem;
    margin-inline: auto;
    pointer-events: none;
}

/* Only where there is room for them beside the emblem. */
@media (min-width: 1024px) {
    :root[data-theme-slug='yatagarasu'] .yata-hero__stage {
        display: block;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-hero__flourish {
    position: absolute;
    width: 22rem;
    filter: drop-shadow(0 14px 34px rgb(0 0 0 / 60%));
    transform-origin: bottom center;
}

@media (min-width: 1280px) {
    :root[data-theme-slug='yatagarasu'] .yata-hero__flourish {
        width: 28rem;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-hero__flourish--left {
    bottom: 5%;
    left: 4%;
    rotate: -6deg;
    animation: yata-float-left 5s ease-in-out 1s infinite;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__flourish--right {
    bottom: 30%;
    right: -1%;
    rotate: 5deg;
    animation: yata-float-right 5s ease-in-out 1.4s infinite;
}

/*
 * Two keyframes rather than one, because each flourish keeps its own tilt
 * through the float -- sharing a keyframe would snap them both to the same
 * rotation on the first frame.
 */
@keyframes yata-float-left {
    0%,
    100% {
        translate: 0 0;
        rotate: -6deg;
    }

    50% {
        translate: 0 -20px;
        rotate: -7.5deg;
    }
}

@keyframes yata-float-right {
    0%,
    100% {
        translate: 0 0;
        rotate: 5deg;
    }

    50% {
        translate: 0 -20px;
        rotate: 6.5deg;
    }
}

/* ---- The words ---------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-hero__inner {
    position: relative;
    z-index: 20;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    max-width: 64rem;
    margin-inline: auto;
    padding: 6rem 1.5rem;
    text-align: center;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__emblem {
    position: relative;
    display: inline-block;
    width: 100%;
    max-width: 47.5rem;
    margin-top: 1.5rem;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__title {
    margin-top: 1rem;
    font-family: var(--yata-font-deco);
    font-size: clamp(2.5rem, 9vw, 5.5rem);
    font-weight: 700;
    line-height: 1;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--yata-ivory);
    text-wrap: balance;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__lead {
    max-width: 42rem;
    margin-top: 2rem;
    font-family: var(--yata-font-body);
    font-style: italic;
    font-size: 1.25rem;
    line-height: 1.7;
    color: rgb(232 213 163 / 80%);
    text-wrap: balance;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__kicker {
    margin-top: 0.875rem;
    font-family: var(--font-display);
    font-size: 0.75rem;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-hero__actions {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 1rem;
    margin-top: 2.5rem;
    width: 100%;
    max-width: 20rem;
}

@media (min-width: 640px) {
    :root[data-theme-slug='yatagarasu'] .yata-hero__actions {
        flex-direction: row;
        justify-content: center;
        width: auto;
        max-width: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    :root[data-theme-slug='yatagarasu'] .yata-hero__flourish {
        animation: none;
    }
}
</style>
