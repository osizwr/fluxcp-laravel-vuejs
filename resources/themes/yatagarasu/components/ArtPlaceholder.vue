<script setup lang="ts">
import { ref } from 'vue'

/**
 * Where artwork goes, before there is any.
 *
 * This theme ships no game artwork. The original client's art is not licensed
 * for redistribution with this project, so every image in the design is a
 * named slot instead, filled per installation by whoever holds the rights.
 *
 * The slot is not inert: it requests the file it expects, and falls back to a
 * labelled placeholder when that 404s. Supplying the art is therefore dropping
 * files into `public/images/` — no code change, no configuration, and no
 * registry of which images happen to exist. The cost is one failed request per
 * missing image, which stops the moment the file is added, and which is the
 * right trade for art that is meant to be supplied per installation.
 *
 * The placeholder prints the path it is waiting for, so whoever supplies the
 * artwork knows where each piece goes without reading the source.
 */
const props = withDefaults(
    defineProps<{
        /** Public path this slot expects, e.g. `/images/hero/world.webp`. */
        path: string
        /** What the image should depict; the alt text once supplied. */
        alt: string
        /** Tailwind sizing, so the slot reserves its space before loading. */
        ratio?: string
        /** The hero's art should not be lazy; everything below the fold should. */
        eager?: boolean
        /**
         * Where the placeholder label sits.
         *
         * Centred is right for a slot that is its own box. Full-bleed art has
         * the page's own words over it, so there the label goes into a corner
         * instead of landing on top of them.
         */
        anchor?: 'center' | 'corner'
    }>(),
    { ratio: 'aspect-[16/9]', eager: false, anchor: 'center' },
)

/*
 * Starts out assuming the art exists. If the request fails the <img> is
 * replaced by the label, so the first paint is never a flash of placeholder on
 * an installation that does have the artwork.
 */
const missing = ref(false)
</script>

<template>
    <figure
        class="yata-art m-0"
        :class="[ratio, props.anchor === 'corner' ? 'yata-art--corner' : '']"
    >
        <img
            v-if="!missing"
            :src="props.path"
            :alt="props.alt"
            class="absolute inset-0 h-full w-full object-cover"
            :loading="props.eager ? 'eager' : 'lazy'"
            :fetchpriority="props.eager ? 'high' : 'auto'"
            decoding="async"
            @error="missing = true"
        />

        <figcaption v-else class="yata-art__label">
            {{ props.path }}
        </figcaption>
    </figure>
</template>
