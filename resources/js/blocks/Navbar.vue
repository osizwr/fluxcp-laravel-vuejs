<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { useAppearance } from '../composables/useAppearance'
import { useGame } from '../composables/useGame'
import { useShell } from '../composables/useShell'
import { useAuthStore } from '../stores/auth'
import { useServerStore } from '../stores/server'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * The core navigation bar.
 *
 * Behaviour comes from useShell(): the link list, active-route matching and
 * the sign-out sequence. This block decides only how that looks, which is why
 * a theme can replace it without reimplementing any of it.
 */
const auth = useAuthStore()
const servers = useServerStore()
const { game, title } = useGame()
const { appearance, toggleAppearance } = useAppearance()
const { links, isActive, menuOpen, signingOut, signOut } = useShell()
</script>

<template>
    <header class="border-b border-[var(--border-subtle)] bg-[var(--surface-raised)]">
        <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-2.5">
            <RouterLink to="/" class="flex items-center gap-2 font-semibold tracking-tight">
                <span
                    class="grid size-6 place-items-center rounded bg-[var(--color-accent-600)] text-[0.7rem] font-bold text-white"
                    aria-hidden="true"
                >
                    {{ game.shortName.slice(0, 2).toUpperCase() }}
                </span>
                <span class="hidden sm:inline">{{ title }}</span>
            </RouterLink>

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
                <nav :aria-label="t('nav.label')" class="hidden items-center gap-0.5 md:flex">
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
                        <span class="text-sm text-[var(--text-secondary)]">
                            {{ auth.account?.username }}
                        </span>
                        <AppButton size="sm" :loading="signingOut" @click="signOut">
                            {{ t('nav.signOut') }}
                        </AppButton>
                    </template>
                    <AppButton v-else variant="primary" size="sm" to="/sign-in">{{
                        t('auth.signIn')
                    }}</AppButton>
                </div>

                <button
                    type="button"
                    class="rounded-[var(--radius-panel)] p-1.5 text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] md:hidden"
                    :aria-expanded="menuOpen"
                    aria-controls="core-nav"
                    :aria-label="t('nav.menu')"
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

        <nav
            v-if="menuOpen"
            id="core-nav"
            :aria-label="t('nav.label')"
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
                <AppButton v-if="auth.isAuthenticated" block :loading="signingOut" @click="signOut">
                    {{ t('nav.signOut') }}
                </AppButton>
                <AppButton v-else variant="primary" block to="/sign-in" @click="menuOpen = false">
                    {{ t('auth.signIn') }}
                </AppButton>
            </div>
        </nav>
    </header>
</template>
