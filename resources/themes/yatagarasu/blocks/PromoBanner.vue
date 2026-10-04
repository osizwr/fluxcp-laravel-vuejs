<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import ArtPlaceholder from '../components/ArtPlaceholder.vue'

/**
 * Yatagarasu — the feature banner, directly under the hero.
 *
 * A theme-only block: the core has no equivalent, and the registry resolves a
 * theme's own blocks by name, so composing it into theme.json is all it takes.
 *
 * Asymmetric by design — artwork on one side, words on the other, with the
 * two overlapping slightly so the panel reads as one object rather than two
 * columns. The heading is this theme's own editorial, not the game's name,
 * so it is written here; the description is the operator's, from GAME_DESCRIPTION.
 */
const { game, title } = useGame()
</script>

<template>
    <section class="yata-air yata-promo" aria-labelledby="yata-promo-title">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:py-20">
            <div class="yata-plate yata-promo__plate">
                <!-- Artwork. Roughly 45% of the banner, as the brief asks. -->
                <div class="yata-promo__art">
                    <ArtPlaceholder
                        path="/images/world/yatagarasu-gate.webp"
                        alt="A great torii gate at the threshold of the realm, lit from within"
                        ratio="h-full w-full min-h-[16rem]"
                    />
                    <div class="yata-promo__art-fade" aria-hidden="true" />
                </div>

                <!-- Words. -->
                <div class="yata-promo__copy">
                    <p class="yata-eyebrow">Enter the realm</p>

                    <h2 id="yata-promo-title" class="yata-title mt-4 text-2xl sm:text-[2rem]">
                        The World of {{ title }}
                    </h2>

                    <hr class="yata-rule my-6 max-w-[14rem]" />

                    <p class="max-w-md text-[0.9375rem] leading-relaxed text-[var(--text-secondary)]">
                        {{ game.description }}
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3.5">
                        <RouterLink to="/register" class="yata-btn yata-btn--primary">
                            Begin your journey
                        </RouterLink>
                        <a
                            v-if="game.links.discord"
                            :href="game.links.discord"
                            class="yata-btn yata-btn--secondary"
                            rel="noreferrer noopener"
                        >
                            Join the community
                        </a>
                        <RouterLink v-else to="/who-is-online" class="yata-btn yata-btn--secondary">
                            See who is online
                        </RouterLink>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-promo__plate {
    display: grid;
    overflow: hidden;
}

@media (min-width: 900px) {
    :root[data-theme-slug='yatagarasu'] .yata-promo__plate {
        grid-template-columns: 45% 1fr;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-promo__art {
    position: relative;
    min-height: 16rem;
}

:root[data-theme-slug='yatagarasu'] .yata-promo__art figure {
    height: 100%;
    border: 0;
}

/*
 * The seam between picture and plate. A fade in the plate's own colour, run
 * along whichever edge the words are on, so the artwork ends in the panel
 * instead of against a hard line.
 */
:root[data-theme-slug='yatagarasu'] .yata-promo__art-fade {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        180deg,
        transparent 40%,
        color-mix(in oklab, var(--surface-raised) 92%, transparent) 100%
    );
}

@media (min-width: 900px) {
    :root[data-theme-slug='yatagarasu'] .yata-promo__art-fade {
        background: linear-gradient(
            90deg,
            transparent 45%,
            color-mix(in oklab, var(--surface-raised) 92%, transparent) 100%
        );
    }
}

:root[data-theme-slug='yatagarasu'] .yata-promo__copy {
    padding: 2.5rem 1.75rem;
}

@media (min-width: 900px) {
    :root[data-theme-slug='yatagarasu'] .yata-promo__copy {
        padding: 3.5rem 3rem;
        align-self: center;
    }
}
</style>
