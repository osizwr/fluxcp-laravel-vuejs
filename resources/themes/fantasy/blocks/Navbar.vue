<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import { useAppearance } from '@/composables/useAppearance'
import { useGame } from '@/composables/useGame'
import { useShell } from '@/composables/useShell'
import { useAuthStore } from '@/stores/auth'
import { useServerStore } from '@/stores/server'
import GameMark from '../components/GameMark.vue'

/**
 * Fantasy — the masthead.
 *
 * Treated as the head of a ledger page rather than an app bar: an inscribed
 * title, navigation as small engraved labels underlined in gold when current,
 * and the live player count set apart with the arcane dot because it is the
 * one figure that changes by itself.
 *
 * A block rather than part of a layout, so both the public and application
 * shells get the same masthead and it is replaceable on its own.
 *
 * Behaviour is entirely from useShell() and useGame(). Nothing here decides
 * anything.
 */
const auth = useAuthStore()
const servers = useServerStore()

const { appearance, toggleAppearance } = useAppearance()
const { game, title } = useGame()
const { links, isActive, menuOpen, signingOut, signOut } = useShell()
</script>

<template>
    <header class="relative bg-[var(--surface-raised)]">
        <div class="mx-auto flex max-w-6xl items-center gap-5 px-4 py-3">
            <RouterLink to="/" class="flex items-center gap-2.5">
                <GameMark :size="30" />
                <span class="flex flex-col leading-none">
                    <span
                        class="font-[family-name:var(--font-display)] text-[0.95rem] font-semibold tracking-[0.08em] text-[var(--text-primary)] uppercase"
                    >
                        {{ title }}
                    </span>
                    <span
                        v-if="game.description"
                        class="mt-0.5 hidden text-[0.65rem] tracking-[0.14em] text-[var(--text-muted)] uppercase sm:block"
                    >
                        {{ game.description }}
                    </span>
                </span>
            </RouterLink>

            <!-- Information, not a destination, so it sits beside the title. -->
            <p
                v-if="servers.groups.length > 0"
                class="theme-live tabular ml-1 hidden text-[0.8125rem] text-[var(--text-secondary)] lg:block"
            >
                <template v-if="servers.anyServerUp">
                    <span class="font-semibold text-[var(--text-primary)]">
                        {{ servers.playersOnline.toLocaleString() }}
                    </span>
                    online
                </template>
                <template v-else>Servers offline</template>
            </p>

            <div class="ml-auto flex items-center gap-1.5">
                <nav aria-label="Main" class="hidden items-center md:flex">
                    <RouterLink
                        v-for="link in links"
                        :key="link.to"
                        :to="link.to"
                        class="relative px-3 py-2 text-[0.72rem] font-semibold tracking-[0.11em] uppercase transition-colors"
                        :class="
                            isActive(link.to)
                                ? 'text-[var(--color-accent-300)]'
                                : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                        "
                        :aria-current="isActive(link.to) ? 'page' : undefined"
                    >
                        {{ link.label }}
                        <span
                            v-if="isActive(link.to)"
                            class="absolute inset-x-2 -bottom-px h-px bg-[var(--color-accent-500)]"
                            aria-hidden="true"
                        />
                    </RouterLink>
                </nav>

                <button
                    type="button"
                    class="rounded-[var(--radius-panel)] p-2 text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-hover)] hover:text-[var(--color-accent-300)]"
                    :aria-label="`Switch to ${appearance === 'dark' ? 'light' : 'dark'} appearance`"
                    @click="toggleAppearance"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            v-if="appearance === 'dark'"
                            d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm0 12a4 4 0 100-8 4 4 0 000 8zm7-4a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zm10.07-5.07a1 1 0 010 1.414l-.707.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM6.05 13.95a1 1 0 010 1.414l-.707.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zm8.485 2.121a1 1 0 01-1.414 0l-.707-.707a1 1 0 111.414-1.414l.707.707a1 1 0 010 1.414zM6.757 6.757a1 1 0 01-1.414 0l-.707-.707A1 1 0 016.05 4.636l.707.707a1 1 0 010 1.414zM10 16a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1z"
                        />
                        <path
                            v-else
                            d="M8.228 2.533a7.5 7.5 0 109.239 9.239.75.75 0 00-.98-.98 6 6 0 01-7.28-7.28.75.75 0 00-.979-.979z"
                        />
                    </svg>
                </button>

                <div class="hidden items-center gap-2 md:flex">
                    <template v-if="auth.isAuthenticated">
                        <span
                            class="font-[family-name:var(--font-display)] text-[0.8rem] tracking-wide text-[var(--color-accent-300)]"
                        >
                            {{ auth.account?.username }}
                        </span>
                        <AppButton size="sm" :loading="signingOut" @click="signOut">
                            Sign out
                        </AppButton>
                    </template>
                    <AppButton v-else variant="primary" size="sm" to="/sign-in">Sign in</AppButton>
                </div>

                <button
                    type="button"
                    class="rounded-[var(--radius-panel)] p-2 text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] md:hidden"
                    :aria-expanded="menuOpen"
                    aria-controls="fantasy-nav"
                    aria-label="Menu"
                    @click="menuOpen = !menuOpen"
                >
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            fill-rule="evenodd"
                            d="M3 5.75A.75.75 0 013.75 5h12.5a.75.75 0 010 1.5H3.75A.75.75 0 013 5.75zm0 4.5A.75.75 0 013.75 9.5h12.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75zm0 4.5a.75.75 0 01.75-.75h12.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Forged edge: a bronze hairline with a brighter gold core. -->
        <div
            class="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-[var(--color-accent-600)] to-transparent opacity-60"
            aria-hidden="true"
        />

        <nav
            v-if="menuOpen"
            id="fantasy-nav"
            aria-label="Main"
            class="border-t border-[var(--border-subtle)] bg-[var(--surface-sunken)] px-4 py-2 md:hidden"
        >
            <RouterLink
                v-for="link in links"
                :key="link.to"
                :to="link.to"
                class="block border-l-2 px-3 py-2.5 text-[0.75rem] font-semibold tracking-[0.1em] uppercase"
                :class="
                    isActive(link.to)
                        ? 'border-[var(--color-accent-500)] text-[var(--color-accent-300)]'
                        : 'border-transparent text-[var(--text-secondary)]'
                "
                @click="menuOpen = false"
            >
                {{ link.label }}
            </RouterLink>

            <div class="mt-2 border-t border-[var(--border-subtle)] pt-2">
                <AppButton v-if="auth.isAuthenticated" block :loading="signingOut" @click="signOut">
                    Sign out
                </AppButton>
                <AppButton v-else variant="primary" block to="/sign-in" @click="menuOpen = false">
                    Sign in
                </AppButton>
            </div>
        </nav>
    </header>
</template>
