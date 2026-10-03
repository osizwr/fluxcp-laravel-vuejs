<script setup lang="ts">
import { computed } from 'vue'
import { useGame } from '@/composables/useGame'

/**
 * The brand mark: the operator's logo if they configured one, otherwise a
 * sigil drawn from the game's short name.
 *
 * Generated rather than shipped as an image, for three reasons: no binary
 * asset to licence or maintain, it adapts to whatever GAME_SHORT_NAME is set
 * to, and it inherits the theme's gold so it stays correct in both
 * appearances.
 *
 * Presentation only. It reads configuration and draws; it decides nothing.
 */
const props = withDefaults(defineProps<{ size?: number }>(), { size: 28 })

const { game } = useGame()

const initials = computed(() => game.value.shortName.slice(0, 2).toUpperCase() || '??')
</script>

<template>
    <img
        v-if="game.logo"
        :src="game.logo"
        :alt="game.name"
        :height="props.size"
        class="w-auto"
        :style="{ height: `${props.size}px` }"
    />

    <!--
        A shield inside a ring. aria-hidden because the adjacent text already
        names the server; announcing it twice is noise for a screen reader.
    -->
    <svg
        v-else
        :width="props.size"
        :height="props.size"
        viewBox="0 0 40 40"
        aria-hidden="true"
        class="shrink-0"
    >
        <circle
            cx="20"
            cy="20"
            r="18.5"
            fill="none"
            stroke="var(--color-accent-600)"
            stroke-width="1"
            opacity="0.55"
        />
        <path
            d="M20 4.5 33 9.5v11.2c0 7.6-5.3 13.1-13 15.3-7.7-2.2-13-7.7-13-15.3V9.5Z"
            fill="color-mix(in oklab, var(--color-accent-700) 22%, transparent)"
            stroke="var(--color-accent-500)"
            stroke-width="1.25"
        />
        <text
            x="20"
            y="24.5"
            text-anchor="middle"
            font-family="var(--font-display)"
            font-size="13"
            font-weight="700"
            letter-spacing="0.5"
            fill="var(--color-accent-300)"
        >
            {{ initials }}
        </text>
    </svg>
</template>
