<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import GameMark from '../components/GameMark.vue'

/**
 * Fantasy — the shell for pages reached before signing in.
 *
 * Deliberately empty of navigation. A sign-in page with a full masthead gives
 * a visitor a dozen places to go instead of the one thing they came to do, and
 * most of those places would bounce them straight back here.
 */
const { game, title } = useGame()
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <RouterLink to="/" class="mb-7 flex flex-col items-center gap-3 text-center">
            <GameMark :size="52" />
            <span class="flex flex-col gap-1">
                <span
                    class="font-[family-name:var(--font-display)] text-lg font-semibold tracking-[0.1em] uppercase"
                >
                    {{ title }}
                </span>
                <span
                    v-if="game.description"
                    class="text-[0.68rem] tracking-[0.16em] text-[var(--text-muted)] uppercase"
                >
                    {{ game.description }}
                </span>
            </span>
        </RouterLink>

        <main id="main" class="w-full max-w-sm">
            <slot />
        </main>

        <hr class="theme-rule mt-9 w-full max-w-sm" />

        <p class="mt-4 text-[0.7rem] tracking-[0.1em] text-[var(--text-muted)] uppercase">
            {{ title }}
        </p>
    </div>
</template>
