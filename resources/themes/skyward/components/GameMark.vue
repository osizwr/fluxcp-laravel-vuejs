<script setup lang="ts">
import { computed } from 'vue'
import { useGame } from '@/composables/useGame'

/**
 * The brand mark: the operator's logo if they configured one, otherwise a
 * badge drawn from the game's short name.
 *
 * Generated rather than shipped as an image, for three reasons: no binary
 * asset to licence or maintain, it adapts to whatever GAME_SHORT_NAME is set
 * to, and it inherits the theme's accent so it stays correct in both
 * appearances.
 *
 * Presentation only. It reads configuration and draws; it decides nothing.
 */
/*
 * A number is pixels; a string is any CSS length, so a caller can size the
 * mark responsively -- `clamp(...)`, `vw` -- without having to recompute it in
 * script on every resize.
 */
const props = withDefaults(defineProps<{ size?: number | string }>(), { size: 34 })

const { game } = useGame()

const initials = computed(() => game.value.shortName.slice(0, 2).toUpperCase() || '??')

/** The height to draw at, as a CSS length. */
const height = computed(() => (typeof props.size === 'number' ? `${props.size}px` : props.size))

/*
 * The generated badge is square and drawn in SVG user units, so it needs a
 * number. A caller using a CSS length is sizing a supplied logo, where the
 * badge is not rendered at all; the fallback keeps the viewBox sane regardless.
 */
const svgSize = computed(() => (typeof props.size === 'number' ? props.size : 40))

/*
 * The gradient needs ids that cannot collide, because the masthead and the
 * hero both mount this component on the same page and duplicate SVG ids
 * resolve to whichever came first.
 */
const uid = computed(() => `sky-mark-${svgSize.value}`)
</script>

<template>
    <!--
        Height is set and width is left to follow it, so the logo keeps the
        aspect ratio of whatever file the operator supplied. Setting both -- or
        letting a caller's utility class set a width against this height --
        stretches it, which is the one thing a brand mark must never do.
    -->
    <img
        v-if="game.logo"
        :src="game.logo"
        :alt="game.name"
        class="sky-mark"
        :style="{ height, width: 'auto' }"
    />

    <!--
        A rounded badge with the initials. aria-hidden because the adjacent
        text already names the server; announcing it twice is noise for a
        screen reader.
    -->
    <svg
        v-else
        :width="svgSize"
        :height="svgSize"
        viewBox="0 0 40 40"
        aria-hidden="true"
        class="shrink-0"
    >
        <defs>
            <linearGradient :id="uid" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="var(--color-accent-400)" />
                <stop offset="100%" stop-color="var(--color-accent-600)" />
            </linearGradient>
        </defs>

        <rect x="1" y="1" width="38" height="38" rx="12" :fill="`url(#${uid})`" />

        <!-- A highlight across the top third, so the badge reads as moulded. -->
        <path
            d="M1 13a12 12 0 0112-12h14a12 12 0 0112 12c-8 4-30 4-38 0Z"
            fill="white"
            opacity="0.18"
        />

        <text
            x="20"
            y="25.5"
            text-anchor="middle"
            font-family="var(--font-display)"
            font-size="15"
            font-weight="800"
            letter-spacing="-0.5"
            fill="white"
        >
            {{ initials }}
        </text>
    </svg>
</template>
