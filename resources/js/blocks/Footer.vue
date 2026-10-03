<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useGame } from '../composables/useGame'
import { useShell } from '../composables/useShell'

/**
 * The core footer.
 *
 * Only links the operator configured are rendered, so the footer never carries
 * an item that leads nowhere. There are no invented social links.
 */
const { game, title, links: externalLinks } = useGame()
const { links } = useShell()
</script>

<template>
    <footer class="mt-8 border-t border-[var(--border-subtle)] py-6">
        <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 text-[0.8125rem] text-[var(--text-muted)]">
            <div class="flex flex-wrap items-start justify-between gap-5">
                <div>
                    <p class="font-semibold text-[var(--text-secondary)]">{{ title }}</p>
                    <p v-if="game.description" class="mt-0.5">{{ game.description }}</p>
                    <p v-if="game.version" class="mt-0.5">Version {{ game.version }}</p>
                </div>

                <nav aria-label="Pages" class="flex flex-col gap-1.5">
                    <RouterLink
                        v-for="link in links"
                        :key="link.to"
                        :to="link.to"
                        class="hover:text-[var(--text-secondary)]"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>

                <nav v-if="externalLinks.length > 0" aria-label="Elsewhere" class="flex flex-col gap-1.5">
                    <a
                        v-for="[key, url] in externalLinks"
                        :key="key"
                        :href="url"
                        rel="noreferrer noopener"
                        target="_blank"
                        class="capitalize hover:text-[var(--text-secondary)]"
                    >
                        {{ key }}
                    </a>
                </nav>
            </div>

            <p class="border-t border-[var(--border-subtle)] pt-3">
                A control panel for
                <a
                    href="https://rathena.org"
                    rel="noreferrer noopener"
                    target="_blank"
                    class="underline hover:text-[var(--text-secondary)]"
                    >rAthena</a
                >
                servers.
            </p>
        </div>
    </footer>
</template>
