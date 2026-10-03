<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import { useShell } from '@/composables/useShell'
import GameMark from '../components/GameMark.vue'

/**
 * Fantasy — the footer.
 *
 * Closed with an ornamental rule rather than a border, and set in small
 * engraved capitals. Only links the operator configured are rendered: there
 * are no invented social accounts.
 */
const { game, title, links: externalLinks } = useGame()
const { links } = useShell()
</script>

<template>
    <footer class="mt-6 pb-7">
        <div class="mx-auto max-w-6xl px-4">
            <hr class="theme-rule mb-6" />

            <div class="flex flex-wrap items-start justify-between gap-7">
                <div class="flex items-start gap-3">
                    <GameMark :size="34" />
                    <div>
                        <p
                            class="font-[family-name:var(--font-display)] text-sm tracking-[0.1em] uppercase"
                        >
                            {{ title }}
                        </p>
                        <p
                            v-if="game.description"
                            class="mt-1 text-[0.7rem] tracking-[0.12em] text-[var(--text-muted)] uppercase"
                        >
                            {{ game.description }}
                        </p>
                        <p v-if="game.version" class="mt-1 text-[0.7rem] text-[var(--text-muted)]">
                            Version {{ game.version }}
                        </p>
                    </div>
                </div>

                <nav aria-label="Pages" class="flex flex-col gap-2">
                    <RouterLink
                        v-for="link in links"
                        :key="link.to"
                        :to="link.to"
                        class="text-[0.7rem] tracking-[0.12em] text-[var(--text-muted)] uppercase transition-colors hover:text-[var(--color-accent-300)]"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>

                <nav
                    v-if="externalLinks.length > 0"
                    aria-label="Elsewhere"
                    class="flex flex-col gap-2"
                >
                    <a
                        v-for="[key, url] in externalLinks"
                        :key="key"
                        :href="url"
                        rel="noreferrer noopener"
                        target="_blank"
                        class="text-[0.7rem] tracking-[0.12em] text-[var(--text-muted)] capitalize transition-colors hover:text-[var(--color-accent-300)]"
                    >
                        {{ key }}
                    </a>
                </nav>
            </div>

            <p class="mt-6 text-[0.7rem] text-[var(--text-muted)]">
                A control panel for
                <a
                    href="https://rathena.org"
                    rel="noreferrer noopener"
                    target="_blank"
                    class="underline hover:text-[var(--color-accent-300)]"
                    >rAthena</a
                >
                servers.
            </p>
        </div>
    </footer>
</template>
