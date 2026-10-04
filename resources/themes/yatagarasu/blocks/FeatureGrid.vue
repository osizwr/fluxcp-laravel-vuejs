<script setup lang="ts">
import { RouterLink } from 'vue-router'
import StateBlock from '@/components/ui/StateBlock.vue'
import { useFeatureData } from '@/blocks/data'
import type { FeatureProps } from '@/blocks/contracts'
import SectionHeading from '../components/SectionHeading.vue'

/**
 * Yatagarasu — what the world offers.
 *
 * Codex entries rather than feature cards: a numbered folio, a rule, a title
 * and a line of description. The number is what does the work — it turns a
 * grid of equivalent boxes into a set of entries that belong to one volume.
 *
 * The entries themselves are the operator's, configured in config/game.php,
 * so a server that offers something unusual says so without editing a theme.
 */
const props = withDefaults(defineProps<FeatureProps>(), { heading: 'The World Awaits' })

const features = useFeatureData()
</script>

<template>
    <section aria-labelledby="yata-features-title">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:py-20">
            <SectionHeading
                eyebrow="Codex"
                :title="props.heading"
                title-id="yata-features-title"
                lead="What waits for those who answer the call."
            />

            <StateBlock
                v-if="features.state.empty"
                class="mt-12"
                variant="empty"
                title="The codex is yet to be written"
                description="Features appear here once the operator has described them."
            />

            <ul v-else class="yata-codex mt-12">
                <li
                    v-for="(item, index) in features.items"
                    :key="item.title"
                    class="yata-plate yata-plate--interactive yata-codex__entry"
                >
                    <component
                        :is="item.url ? RouterLink : 'div'"
                        v-bind="item.url ? { to: item.url } : {}"
                        class="yata-codex__body"
                    >
                        <p class="yata-codex__no">{{ String(index + 1).padStart(2, '0') }}</p>

                        <h3 class="yata-codex__title">{{ item.title }}</h3>

                        <hr class="yata-rule my-4 max-w-[5rem]" />

                        <p class="yata-codex__text">{{ item.description }}</p>

                        <p v-if="item.url" class="yata-codex__more" aria-hidden="true">
                            Read on &rarr;
                        </p>
                    </component>
                </li>
            </ul>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-codex {
    display: grid;
    gap: 1rem;
    margin: 0;
    padding: 0;
    list-style: none;
    grid-template-columns: repeat(1, minmax(0, 1fr));
}

@media (min-width: 640px) {
    :root[data-theme-slug='yatagarasu'] .yata-codex {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (min-width: 1024px) {
    :root[data-theme-slug='yatagarasu'] .yata-codex {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

:root[data-theme-slug='yatagarasu'] .yata-codex__body {
    display: block;
    padding: 2rem 1.75rem;
    height: 100%;
    text-decoration: none;
}

/* The folio number, set large and faint behind the title. */
:root[data-theme-slug='yatagarasu'] .yata-codex__no {
    font-family: var(--font-display);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.2em;
    color: var(--color-accent-600);
}

:root[data-theme-slug='yatagarasu'] .yata-codex__title {
    margin-top: 0.875rem;
    font-family: var(--font-display);
    font-size: 1.1875rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-codex__text {
    font-size: 0.9375rem;
    line-height: 1.65;
    color: var(--text-secondary);
}

:root[data-theme-slug='yatagarasu'] .yata-codex__more {
    margin-top: 1.25rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--color-accent-500);
    opacity: 0;
    transition: opacity 200ms var(--yata-ease);
}

/* The prompt appears on approach; the entry is readable without it. */
:root[data-theme-slug='yatagarasu'] .yata-codex__entry:hover .yata-codex__more,
:root[data-theme-slug='yatagarasu'] .yata-codex__body:focus-visible .yata-codex__more {
    opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
    :root[data-theme-slug='yatagarasu'] .yata-codex__more {
        opacity: 1;
        transition: none;
    }
}
</style>
