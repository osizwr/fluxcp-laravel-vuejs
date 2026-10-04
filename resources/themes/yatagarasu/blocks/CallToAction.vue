<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useCallToActionData } from '@/blocks/data'
import type { CallToActionProps } from '@/blocks/contracts'
import { useGame } from '@/composables/useGame'
import ArtPlaceholder from '../components/ArtPlaceholder.vue'

/**
 * Yatagarasu — the last word.
 *
 * The strongest visual moment after the hero, and deliberately the simplest:
 * artwork, one line, two controls. By this point in the page the reader has
 * been given every figure the server has; what is left is to ask.
 *
 * The heading and description come from the composable, so an operator's own
 * wording wins over the theme's.
 */
const props = defineProps<CallToActionProps>()

const cta = useCallToActionData(props.heading, props.description)
const { game } = useGame()
</script>

<template>
    <section class="yata-cta" aria-labelledby="yata-cta-title">
        <div class="yata-cta__art">
            <ArtPlaceholder
                path="/images/world/yatagarasu-dawn.webp"
                alt="Dawn breaking over the realm, seen from a high road"
                ratio="h-full w-full"
                anchor="corner"
            />
        </div>

        <div class="yata-cta__veil" aria-hidden="true" />

        <div class="yata-cta__inner">
            <h2 id="yata-cta-title" class="yata-cta__title">{{ cta.title }}</h2>

            <hr class="yata-rule-diamond my-7 w-full max-w-[16rem]" />

            <p v-if="cta.description" class="yata-cta__lead">{{ cta.description }}</p>

            <div class="yata-cta__actions">
                <RouterLink
                    v-if="cta.action?.to"
                    :to="cta.action.to"
                    class="yata-btn yata-btn--primary"
                >
                    {{ cta.action.label }}
                </RouterLink>
                <a
                    v-else-if="cta.action?.href"
                    :href="cta.action.href"
                    class="yata-btn yata-btn--primary"
                    rel="noreferrer noopener"
                >
                    {{ cta.action.label }}
                </a>

                <!--
                    The download, when the operator has published one. A client
                    this server does not distribute is not offered.
                -->
                <a
                    v-if="game.links.downloads"
                    :href="game.links.downloads"
                    class="yata-btn yata-btn--secondary"
                    rel="noreferrer noopener"
                >
                    Download the client
                </a>
                <RouterLink
                    v-else-if="cta.secondaryAction?.to"
                    :to="cta.secondaryAction.to"
                    class="yata-btn yata-btn--secondary"
                >
                    {{ cta.secondaryAction.label }}
                </RouterLink>
            </div>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-cta {
    position: relative;
    overflow: hidden;
    isolation: isolate;
}

:root[data-theme-slug='yatagarasu'] .yata-cta__art,
:root[data-theme-slug='yatagarasu'] .yata-cta__art figure {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-cta__veil {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            180deg,
            var(--surface-page) 0%,
            color-mix(in oklab, var(--yata-void) 78%, transparent) 30%,
            color-mix(in oklab, var(--yata-void) 88%, transparent) 100%
        ),
        /* A trace of gold along the horizon, where the dawn would be. */
            radial-gradient(
                90% 60% at 50% 100%,
                color-mix(in oklab, var(--yata-gold) 12%, transparent),
                transparent 70%
            );
}

:root[data-theme-slug='yatagarasu'] .yata-cta__inner {
    position: relative;
    max-width: 44rem;
    margin-inline: auto;
    padding: 6rem 1rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-cta__inner {
        padding-block: 8rem;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-cta__title {
    font-family: var(--font-display);
    font-size: clamp(2rem, 6vw, 3.25rem);
    font-weight: 700;
    line-height: 1.1;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--yata-ivory);
    text-wrap: balance;
}

:root[data-theme-slug='yatagarasu'] .yata-cta__lead {
    max-width: 34rem;
    font-size: 1.0625rem;
    line-height: 1.7;
    color: color-mix(in oklab, var(--yata-ivory) 80%, transparent);
}

:root[data-theme-slug='yatagarasu'] .yata-cta__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.875rem;
    margin-top: 2.5rem;
}

@media (max-width: 479px) {
    /* Full-width controls where there is no room for two side by side. */
    :root[data-theme-slug='yatagarasu'] .yata-cta__actions {
        flex-direction: column;
        align-self: stretch;
    }
}
</style>
