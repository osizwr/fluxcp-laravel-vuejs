<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useCallToActionData } from '@/blocks/data'
import type { CallToActionProps } from '@/blocks/contracts'
import { useGame } from '@/composables/useGame'
import ArtPlaceholder from '../components/ArtPlaceholder.vue'
import CrowMark from '../components/CrowMark.vue'

/**
 * Yatagarasu — the last word.
 *
 * The mark, a cast-gold title, one line in italic and one control. By this
 * point in the page the reader has been given every figure the server has;
 * what is left is to ask.
 *
 * The artwork is masked to fade in from the top rather than cropped, so it
 * emerges out of the section above instead of starting at a hard edge, and
 * the negative margin at the foot lets it run flush into the footer.
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
                alt="A champion of the realm at dawn"
                ratio="h-full w-full"
                anchor="corner"
            />
        </div>

        <div class="yata-cta__veil" aria-hidden="true" />

        <div class="yata-cta__inner">
            <CrowMark :size="56" class="text-[var(--color-accent-500)]" />

            <h2 id="yata-cta-title" class="yata-title-cast yata-cta__title">
                {{ cta.title }}
            </h2>

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

                <!-- The download, when the operator has published one. A client
                     this server does not distribute is not offered. -->
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
    z-index: 10;
    overflow: hidden;
    padding-block: 7.5rem;
    /* Cancels the footer's top margin so the artwork meets it flush. */
    margin-bottom: -4rem;
}

:root[data-theme-slug='yatagarasu'] .yata-cta__art,
:root[data-theme-slug='yatagarasu'] .yata-cta__art :is(figure, img) {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
}

/*
 * Masked rather than cropped: the image is transparent at the top and solid
 * from about a third of the way down, so it emerges from the section above
 * instead of beginning at a visible line.
 */
:root[data-theme-slug='yatagarasu'] .yata-cta__art figure {
    opacity: 0.35;
    filter: brightness(1.25);
    -webkit-mask-image: linear-gradient(to bottom, transparent 0%, #000 38%, #000 100%);
    mask-image: linear-gradient(to bottom, transparent 0%, #000 38%, #000 100%);
}

:root[data-theme-slug='yatagarasu'] .yata-cta__veil {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, var(--surface-page) 0%, transparent 45%, transparent 100%);
}

:root[data-theme-slug='yatagarasu'] .yata-cta__inner {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    max-width: 48rem;
    margin-inline: auto;
    padding-inline: 1.5rem;
    text-align: center;
}

:root[data-theme-slug='yatagarasu'] .yata-cta__title {
    margin-top: 2rem;
    font-size: clamp(2rem, 6vw, 3rem);
    line-height: 1.15;
}

:root[data-theme-slug='yatagarasu'] .yata-cta__lead {
    max-width: 36rem;
    margin-top: 1.5rem;
    font-family: var(--yata-font-body);
    font-style: italic;
    font-size: 1.25rem;
    line-height: 1.7;
    color: rgb(232 213 163 / 70%);
    text-wrap: balance;
}

:root[data-theme-slug='yatagarasu'] .yata-cta__actions {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 1rem;
    width: 100%;
    max-width: 20rem;
    margin-top: 3rem;
}

@media (min-width: 640px) {
    :root[data-theme-slug='yatagarasu'] .yata-cta__actions {
        flex-direction: row;
        justify-content: center;
        width: auto;
        max-width: none;
    }
}
</style>
