<script setup lang="ts">
/**
 * The three-legged crow.
 *
 * Yatagarasu is the mythical three-legged crow, so the mark is one: a bird
 * seen head-on with its wings spread, standing on three legs, inside the sun
 * disc it is traditionally shown against.
 *
 * Drawn rather than shipped as an image for two reasons — it inherits
 * `currentColor`, so the same mark works gold in the navigation and muted in
 * the footer, and the theme ships no binary assets, which means nothing to
 * licence and nothing to load.
 *
 * Built as filled silhouettes rather than strokes. An outlined bird is legible
 * at 48px and a smudge at 20px, where a solid shape keeps its read all the way
 * down — which matters, because the smallest use is the one in the footer.
 *
 * It is a motif, not a logo: used small and used rarely. An operator's own
 * logo goes in GAME_LOGO and the navigation shows that instead.
 */
withDefaults(
    defineProps<{
        /** Rendered size in pixels. Square. */
        size?: number
        /** Decorative by default; give a label where it carries meaning. */
        label?: string | null
    }>(),
    { size: 24, label: null },
)
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 64 64"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        :role="label ? 'img' : 'presentation'"
        :aria-label="label ?? undefined"
        :aria-hidden="label ? undefined : true"
        focusable="false"
    >
        <!--
            The sun disc. Open at the top where the wings cross it, so the bird
            reads as being in front of the sun rather than inside a ring.
        -->
        <path
            d="M32 5a27 27 0 1 1 0 54 27 27 0 0 1 0-54"
            stroke="currentColor"
            stroke-width="1.5"
            opacity="0.28"
            stroke-dasharray="66 22"
            stroke-linecap="round"
        />

        <!--
            Wings, as solid swept shapes. Each is one closed path from the
            shoulder out to the tip and back along the trailing edge, which is
            what gives the silhouette its taper.
        -->
        <path
            d="M30 26c-4.5-5.2-10.6-8.4-18.4-9.2 4.2 2.6 6.9 5.6 8.3 9 1 2.4 1.3 4.6 1.4 7.2 2.2-2.4 4.6-3.9 7.5-4.6Z"
            fill="currentColor"
        />
        <path
            d="M34 26c4.5-5.2 10.6-8.4 18.4-9.2-4.2 2.6-6.9 5.6-8.3 9-1 2.4-1.3 4.6-1.4 7.2-2.2-2.4-4.6-3.9-7.5-4.6Z"
            fill="currentColor"
        />

        <!--
            Body and head in one shape, tapering to the tail. The notch at the
            top is the crown of the head.
        -->
        <path
            d="M32 17c2.9 0 5 2.4 5 6 0 2.4-.4 4.6-1 6.8 1.4 3.4 2 7 2 10.9 0 2.6-2.2 5.3-6 7.3-3.8-2-6-4.7-6-7.3 0-3.9.6-7.5 2-10.9-.6-2.2-1-4.4-1-6.8 0-3.6 2.1-6 5-6Z"
            fill="currentColor"
        />

        <!-- Beak. Short, angled down-left, so the mark faces somewhere. -->
        <path d="M27.4 21.6 21 23.4l6.4 2.2Z" fill="currentColor" />

        <!--
            The three legs — the whole point of the name. The centre one is
            drawn longest and straight down, the outer two splayed, so the
            count is readable at a glance and not mistaken for two.
        -->
        <path
            d="M32 47v10M26.5 48.5 21 56.5M37.5 48.5 43 56.5"
            stroke="currentColor"
            stroke-width="2.4"
            stroke-linecap="round"
        />
        <!-- Feet, so the legs end rather than stop. -->
        <path
            d="M29 57.6h6M18.4 57.4l4.6-1.6M45.6 57.4 41 55.8"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
        />
    </svg>
</template>
