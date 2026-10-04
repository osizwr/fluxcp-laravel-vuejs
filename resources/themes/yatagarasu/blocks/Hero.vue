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
 * A near-full-height cinematic plate: key art behind, the game's name over
 * it, and the live state of the world along the bottom.
 *
 * The composition is deliberately asymmetric. The title sits left of centre
 * and the panel that frames it stops well short of the right edge, so the
 * artwork on that side is never covered. A centred title on a full-bleed
 * image is the arrangement every landing page uses and it wastes the half of
 * the picture it sits on.
 *
 * Everything written here is either configuration or live state. The name,
 * tagline and description come from the operator's GAME_* settings through
 * useHeroData(); the player count and server state come from the store the
 * broadcast feeds. Nothing is simulated, and no copy about the game is
 * hardcoded in this file.
 */
const props = withDefaults(defineProps<HeroProps>(), { showStatus: true })

const hero = useHeroData(props)
const { game } = useGame()

/**
 * The vertical index down the left edge.
 *
 * Four numbered words rather than links to sections that may not exist: this
 * is the hero of a page whose composition the theme controls, and a marker
 * pointing at a section an operator removed would be dead navigation. They
 * are labels for the chapters of the page, and the page scrolls past them.
 */
const chapters = ['World', 'Adventure', 'Battle', 'Legends']

/*
 * Drifting motes. Positions are fixed rather than random so that the hero
 * renders identically on the server and the client, and so two visitors are
 * looking at the same picture.
 */
const motes = [
    { left: '12%', delay: '0s', duration: '16s' },
    { left: '26%', delay: '4s', duration: '13s' },
    { left: '41%', delay: '8s', duration: '18s' },
    { left: '58%', delay: '2s', duration: '15s' },
    { left: '73%', delay: '6s', duration: '19s' },
    { left: '88%', delay: '10s', duration: '14s' },
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

        <!-- Layer 2: the night. A vertical fall to black and a vignette. -->
        <div class="yata-hero__veil" aria-hidden="true" />

        <!-- Layer 3: motes, drifting upward. -->
        <div class="yata-hero__motes" aria-hidden="true">
            <span
                v-for="(mote, index) in motes"
                :key="index"
                class="yata-mote"
                :style="{
                    left: mote.left,
                    bottom: '12%',
                    animationDelay: mote.delay,
                    animationDuration: mote.duration,
                }"
            />
        </div>

        <!-- Layer 4: the chapter index, desktop only. -->
        <ol class="yata-hero__chapters" aria-hidden="true">
            <li v-for="(chapter, index) in chapters" :key="chapter">
                <span class="yata-hero__chapter-no">{{ String(index + 1).padStart(2, '0') }}</span>
                <span class="yata-hero__chapter-name">{{ chapter }}</span>
            </li>
        </ol>

        <!-- Layer 5: the words. -->
        <div class="yata-hero__inner">
            <div class="yata-hero__copy yata-rise">
                <CrowMark :size="46" class="mb-6 text-[var(--color-accent-500)]" />

                <h1 id="yata-hero-title" class="yata-hero__title">{{ hero.title }}</h1>

                <!--
                    The subtitle is the short name, by contract. It is printed
                    only when it says something the title does not: on a server
                    whose short name is just its name abbreviated, the slot is
                    better empty than tautological.
                -->
                <p
                    v-if="hero.subtitle && hero.subtitle.toLowerCase() !== hero.title.toLowerCase()"
                    class="yata-hero__subtitle"
                >
                    {{ hero.subtitle }}
                </p>

                <hr class="yata-rule my-7 max-w-[18rem]" />

                <p v-if="hero.description" class="yata-hero__lead">{{ hero.description }}</p>

                <div class="yata-hero__actions">
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
        </div>

        <!--
            Layer 6: the state of the world, along the foot of the hero.

            Part of the artwork rather than a panel on top of it: one hairline
            above, hairlines between, and no fill. The player count is the only
            figure here that is not configuration, and it is live.
        -->
        <div v-if="props.showStatus" class="yata-hero__status">
            <dl class="mx-auto flex max-w-7xl items-stretch px-4">
                <div class="yata-hero__stat">
                    <dt>Realm</dt>
                    <dd>
                        <span
                            class="yata-nav__dot"
                            :class="hero.serversUp ? 'is-up' : 'is-down'"
                            aria-hidden="true"
                        />
                        {{ hero.serversUp ? 'Online' : 'Offline' }}
                    </dd>
                </div>

                <div class="yata-hero__stat">
                    <dt>Adventurers</dt>
                    <dd class="tabular">
                        {{ hero.playersOnline === null ? '—' : hero.playersOnline.toLocaleString() }}
                    </dd>
                </div>

                <div class="yata-hero__stat yata-hero__stat--wide">
                    <dt>Client</dt>
                    <dd>
                        <a
                            v-if="game.links.downloads"
                            :href="game.links.downloads"
                            class="yata-btn yata-btn--ghost"
                            rel="noreferrer noopener"
                        >
                            Download
                        </a>
                        <RouterLink v-else to="/register" class="yata-btn yata-btn--ghost">
                            Create an account
                        </RouterLink>
                    </dd>
                </div>
            </dl>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-hero {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    min-height: 88svh;
    overflow: hidden;
    /*
     * Pulled up under the navbar so the artwork runs to the top of the window
     * and the transparent bar floats on it.
     */
    margin-top: -64px;
    padding-top: 64px;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__art,
:root[data-theme-slug='yatagarasu'] .yata-hero__art :is(figure, img) {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__art figure {
    border: 0;
}

/*
 * The veil. Three stops rather than a single overlay: dark at the very top so
 * the navbar's white capitals hold, clear through the middle where the picture
 * is, and dark again at the foot so the title and the status strip sit on
 * something. A flat 50% scrim over the whole image would be easier and would
 * throw away the artwork.
 */
:root[data-theme-slug='yatagarasu'] .yata-hero__veil {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            180deg,
            color-mix(in oklab, var(--yata-void) 85%, transparent) 0%,
            color-mix(in oklab, var(--yata-void) 20%, transparent) 26%,
            color-mix(in oklab, var(--yata-void) 32%, transparent) 54%,
            color-mix(in oklab, var(--yata-void) 94%, transparent) 100%
        ),
        /* Weighted to the left, where the words are. */
            linear-gradient(
                90deg,
                color-mix(in oklab, var(--yata-navy) 82%, transparent) 0%,
                color-mix(in oklab, var(--yata-navy) 30%, transparent) 46%,
                transparent 72%
            ),
        radial-gradient(
            120% 100% at 50% 50%,
            transparent 42%,
            color-mix(in oklab, var(--yata-void) 62%, transparent) 100%
        );
}

:root[data-theme-slug='yatagarasu'] .yata-hero__motes {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

/* ---- The chapter index ------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-hero__chapters {
    display: none;
    position: absolute;
    left: 2.5rem;
    top: 50%;
    translate: 0 -50%;
    margin: 0;
    padding: 0;
    list-style: none;
    gap: 1.5rem;
    flex-direction: column;
}

@media (min-width: 1280px) {
    :root[data-theme-slug='yatagarasu'] .yata-hero__chapters {
        display: flex;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-hero__chapters li {
    display: flex;
    align-items: baseline;
    gap: 0.625rem;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__chapter-no {
    font-family: var(--font-display);
    font-size: 0.625rem;
    letter-spacing: 0.1em;
    color: var(--color-accent-600);
}

:root[data-theme-slug='yatagarasu'] .yata-hero__chapter-name {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: color-mix(in oklab, var(--yata-ivory) 42%, transparent);
    writing-mode: vertical-rl;
}

/* ---- The words --------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-hero__inner {
    position: relative;
    width: 100%;
    max-width: 80rem;
    margin-inline: auto;
    padding: 4rem 1rem 3rem;
}

@media (min-width: 1280px) {
    :root[data-theme-slug='yatagarasu'] .yata-hero__inner {
        padding-left: 7rem;
    }
}

/*
 * Stops short of the right edge on purpose: the artwork on that side stays
 * visible, which is the whole reason for an asymmetric hero.
 */
:root[data-theme-slug='yatagarasu'] .yata-hero__copy {
    max-width: 38rem;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__title {
    font-family: var(--font-display);
    font-size: clamp(2.75rem, 8vw, 5.25rem);
    font-weight: 700;
    line-height: 1.02;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--yata-ivory);
    text-wrap: balance;
    /*
     * A long, soft shadow rather than a glow. It lifts the letters off
     * whatever is behind them without looking lit.
     */
    text-shadow: 0 2px 30px color-mix(in oklab, var(--yata-void) 85%, transparent);
    margin: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__subtitle {
    margin-top: 1rem;
    font-size: 0.8125rem;
    font-weight: 600;
    letter-spacing: 0.34em;
    text-transform: uppercase;
    color: var(--color-accent-400);
}

:root[data-theme-slug='yatagarasu'] .yata-hero__lead {
    max-width: 32rem;
    font-size: 1.0625rem;
    line-height: 1.7;
    color: color-mix(in oklab, var(--yata-ivory) 82%, transparent);
}

:root[data-theme-slug='yatagarasu'] .yata-hero__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.875rem;
    margin-top: 2.25rem;
}

/* ---- The status strip -------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-hero__status {
    position: relative;
    border-top: 1px solid var(--yata-rule);
    background: linear-gradient(
        180deg,
        transparent,
        color-mix(in oklab, var(--yata-void) 55%, transparent)
    );
}

:root[data-theme-slug='yatagarasu'] .yata-hero__stat {
    flex: 1 1 0;
    padding: 1.125rem 1.25rem;
    min-width: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-hero__stat + .yata-hero__stat {
    border-left: 1px solid var(--yata-rule);
}

:root[data-theme-slug='yatagarasu'] .yata-hero__stat dt {
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-hero__stat dd {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0.4rem 0 0;
    font-family: var(--font-display);
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--yata-ivory);
}

/* The download cell carries a control, which needs no display face. */
:root[data-theme-slug='yatagarasu'] .yata-hero__stat--wide dd {
    font-family: 'Manrope', ui-sans-serif, system-ui, sans-serif;
}

@media (max-width: 639px) {
    :root[data-theme-slug='yatagarasu'] .yata-hero {
        min-height: 94svh;
    }

    /* The third cell wraps to its own row rather than squeezing to nothing. */
    :root[data-theme-slug='yatagarasu'] .yata-hero__status dl {
        flex-wrap: wrap;
    }

    :root[data-theme-slug='yatagarasu'] .yata-hero__stat--wide {
        flex-basis: 100%;
        border-left: 0;
        border-top: 1px solid var(--yata-rule);
    }
}
</style>
