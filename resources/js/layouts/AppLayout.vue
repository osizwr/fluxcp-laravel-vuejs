<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { useAuthStore } from '../stores/auth'
import { useServerStore } from '../stores/server'
import { useAppearance } from '../composables/useAppearance'
import { useGame } from '../composables/useGame'
import { useShell } from '../composables/useShell'

/**
 * The application shell: masthead, navigation, content, footer.
 *
 * The core default. A theme replaces it by providing
 * resources/themes/<slug>/layouts/AppLayout.vue, which is the seam the legacy
 * `bootstrap` theme used too -- it overrode only header, footer and navbar
 * against the default theme's 144 files.
 *
 * Branding comes from useGame() and navigation behaviour from useShell(), so
 * this file is presentation only -- which is what lets a theme replace it
 * without reimplementing how sign-out or active-route matching works.
 */
const auth = useAuthStore()
const servers = useServerStore()
const { appearance, toggleAppearance } = useAppearance()
const { game, title: siteTitle, links: externalLinks } = useGame()

const { links, isActive, menuOpen, signingOut, signOut } = useShell()
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <!--
            A skip link is the one accessibility affordance a navigation-heavy
            page most needs: without it a keyboard user tabs the whole menu on
            every page.
        -->
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-[var(--surface-raised)] focus:px-3 focus:py-2 focus:text-sm"
        >
            Skip to content
        </a>

        <header class="border-b border-[var(--border-subtle)] bg-[var(--surface-raised)]">
            <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-2.5">
                <RouterLink to="/" class="flex items-center gap-2 font-semibold tracking-tight">
                    <span
                        class="grid size-6 place-items-center rounded bg-[var(--color-accent-600)] text-[0.7rem] font-bold text-white"
                        aria-hidden="true"
                    >
                        {{ game.shortName.slice(0, 2).toUpperCase() }}
                    </span>
                    <span class="hidden sm:inline">{{ siteTitle }}</span>
                </RouterLink>

                <!--
                    Live player count in the masthead, which is the figure
                    visitors come to the panel for most often.
                -->
                <StatusPill
                    v-if="servers.groups.length > 0"
                    :state="servers.anyServerUp ? 'up' : 'down'"
                    :label="
                        servers.anyServerUp
                            ? `${servers.playersOnline.toLocaleString()} online`
                            : 'Offline'
                    "
                />

                <div class="ml-auto flex items-center gap-1">
                    <nav aria-label="Main" class="hidden items-center gap-0.5 md:flex">
                        <RouterLink
                            v-for="link in links"
                            :key="link.to"
                            :to="link.to"
                            class="rounded-[var(--radius-panel)] px-2.5 py-1.5 text-sm font-medium transition-colors"
                            :class="
                                isActive(link.to)
                                    ? 'bg-[var(--surface-sunken)] text-[var(--color-accent-600)]'
                                    : 'text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]'
                            "
                            :aria-current="isActive(link.to) ? 'page' : undefined"
                        >
                            {{ link.label }}
                        </RouterLink>
                    </nav>

                    <button
                        type="button"
                        class="rounded-[var(--radius-panel)] p-1.5 text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]"
                        :aria-label="`Switch to ${appearance === 'dark' ? 'light' : 'dark'} appearance`"
                        @click="toggleAppearance"
                    >
                        <svg
                            class="size-4"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            aria-hidden="true"
                        >
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
                            <span class="text-sm text-[var(--text-secondary)]">
                                {{ auth.account?.username }}
                            </span>
                            <AppButton size="sm" :loading="signingOut" @click="signOut">
                                Sign out
                            </AppButton>
                        </template>
                        <AppButton v-else variant="primary" size="sm" to="/sign-in"
                            >Sign in</AppButton
                        >
                    </div>

                    <button
                        type="button"
                        class="rounded-[var(--radius-panel)] p-1.5 text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] md:hidden"
                        :aria-expanded="menuOpen"
                        aria-controls="mobile-nav"
                        aria-label="Menu"
                        @click="menuOpen = !menuOpen"
                    >
                        <svg
                            class="size-5"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M3 5.75A.75.75 0 013.75 5h12.5a.75.75 0 010 1.5H3.75A.75.75 0 013 5.75zm0 4.5A.75.75 0 013.75 9.5h12.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75zm0 4.5a.75.75 0 01.75-.75h12.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                </div>
            </div>

            <nav
                v-if="menuOpen"
                id="mobile-nav"
                aria-label="Main"
                class="border-t border-[var(--border-subtle)] px-4 py-2 md:hidden"
            >
                <RouterLink
                    v-for="link in links"
                    :key="link.to"
                    :to="link.to"
                    class="block rounded-[var(--radius-panel)] px-2.5 py-2 text-sm font-medium"
                    :class="
                        isActive(link.to)
                            ? 'bg-[var(--surface-sunken)] text-[var(--color-accent-600)]'
                            : 'text-[var(--text-secondary)]'
                    "
                    @click="menuOpen = false"
                >
                    {{ link.label }}
                </RouterLink>

                <div class="mt-2 border-t border-[var(--border-subtle)] pt-2">
                    <AppButton
                        v-if="auth.isAuthenticated"
                        block
                        :loading="signingOut"
                        @click="signOut"
                    >
                        Sign out
                    </AppButton>
                    <AppButton
                        v-else
                        variant="primary"
                        block
                        to="/sign-in"
                        @click="menuOpen = false"
                    >
                        Sign in
                    </AppButton>
                </div>
            </nav>
        </header>

        <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
            <slot />
        </main>

        <footer class="border-t border-[var(--border-subtle)] py-4">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 text-[0.8125rem] text-[var(--text-muted)]"
            >
                <p>{{ siteTitle }}</p>

                <!--
                    Only links the operator configured in config/game.php are
                    rendered, so the footer never carries a dead item.
                -->
                <nav
                    v-if="externalLinks.length > 0"
                    aria-label="Elsewhere"
                    class="flex flex-wrap gap-3"
                >
                    <a
                        v-for="[key, url] in externalLinks"
                        :key="key"
                        :href="url"
                        rel="noreferrer noopener"
                        target="_blank"
                        class="capitalize underline hover:text-[var(--text-secondary)]"
                    >
                        {{ key }}
                    </a>
                </nav>

                <p>
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
    </div>
</template>
