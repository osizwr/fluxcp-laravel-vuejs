<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useGame } from '../composables/useGame'

/**
 * The shell for pages a visitor reaches before signing in.
 *
 * Kept deliberately bare: no navigation to places that need a session, and no
 * server status, so the page has one obvious thing to do. A theme replaces it
 * with resources/themes/<slug>/layouts/AuthLayout.vue.
 */
const { game, title } = useGame()
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <header class="border-b border-[var(--border-subtle)] bg-[var(--surface-raised)]">
            <div class="mx-auto flex max-w-6xl items-center px-4 py-2.5">
                <RouterLink to="/" class="flex items-center gap-2 font-semibold tracking-tight">
                    <span
                        class="grid size-6 place-items-center rounded bg-[var(--color-accent-600)] text-[0.7rem] font-bold text-white"
                        aria-hidden="true"
                    >
                        {{ game.shortName.slice(0, 2).toUpperCase() }}
                    </span>
                    {{ title }}
                </RouterLink>
            </div>
        </header>

        <main
            id="main"
            class="mx-auto flex w-full max-w-6xl flex-1 items-start justify-center px-4"
        >
            <slot />
        </main>

        <footer class="border-t border-[var(--border-subtle)] py-4">
            <p class="mx-auto max-w-6xl px-4 text-[0.8125rem] text-[var(--text-muted)]">
                {{ title }}
            </p>
        </footer>
    </div>
</template>
