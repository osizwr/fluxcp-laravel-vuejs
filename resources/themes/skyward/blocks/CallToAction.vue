<script setup lang="ts">
import { ref } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import { useCallToActionData } from '@/blocks/data'
import type { CallToActionProps } from '@/blocks/contracts'
import GameMark from '../components/GameMark.vue'

/**
 * Skyward — the last word.
 *
 * The page closes the way it opened: the server's own key art running edge to
 * edge, the mark, one large line of type and the one thing left to press. The
 * symmetry is the point -- a visitor who has scrolled past every figure the
 * server publishes arrives back at the same invitation they were shown first.
 *
 * Three layers, as in the hero, and for the same reasons:
 *
 *   1. A generated gradient. On an installation with no media this is the
 *      whole background, and it is finished-looking on its own, so there is no
 *      binary asset to licence and nothing that breaks when the server is
 *      renamed.
 *
 *   2. The art slot. It requests its path and simply stays with the gradient
 *      when that 404s, so supplying the art is dropping a file into
 *      `public/images/world/` rather than editing this theme.
 *
 *   3. A scrim under the type. Not decoration: artwork nobody has seen yet
 *      cannot be relied on to be light where the words sit, and this band
 *      carries the only control at the foot of the page.
 *
 * The heading, the description and both actions come from the contract, so
 * this block decides only how they look. Both actions are rendered when they
 * exist; an installation that publishes neither a client download nor a
 * community link shows the single button on its own.
 */
const props = defineProps<CallToActionProps>()

const cta = useCallToActionData(props.heading, props.description)

/*
 * Starts out assuming the art exists, so an installation that has it never
 * flashes the bare gradient first. The gradient is the fallback rather than a
 * labelled placeholder, which is the right call here for the same reason it is
 * in the hero: a box captioned with a file path belongs in a slot the layout
 * is built around, not behind a background that already looks finished.
 */
const artMissing = ref(false)
</script>

<template>
    <section class="sky-cta" aria-labelledby="sky-cta-title">
        <div class="sky-cta__sky" aria-hidden="true" />

        <img
            v-if="!artMissing"
            class="sky-cta__art"
            src="/images/world/skyward-horizon.webp"
            alt=""
            aria-hidden="true"
            loading="lazy"
            decoding="async"
            @error="artMissing = true"
        />

        <div class="sky-cta__scrim" aria-hidden="true" />

        <div class="sky-cta__inner">
            <GameMark :size="46" class="sky-cta__mark" />

            <h2 id="sky-cta-title" class="sky-cta__title">{{ cta.title }}</h2>

            <p v-if="cta.description" class="sky-cta__lead">{{ cta.description }}</p>

            <div class="sky-cta__actions">
                <AppButton
                    v-if="cta.action"
                    variant="primary"
                    :to="cta.action.to"
                    :href="cta.action.href"
                >
                    {{ cta.action.label }}
                </AppButton>
                <AppButton
                    v-if="cta.secondaryAction"
                    :to="cta.secondaryAction.to"
                    :href="cta.secondaryAction.href"
                >
                    {{ cta.secondaryAction.label }}
                </AppButton>
            </div>
        </div>
    </section>
</template>
