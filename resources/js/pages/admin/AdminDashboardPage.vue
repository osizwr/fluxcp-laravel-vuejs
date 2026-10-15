<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useTranslation } from '../../i18n'

/**
 * The dashboard's front page.
 *
 * A way in to the sections and nothing else. It deliberately shows no counts,
 * no charts and no "recent activity": every one of those would be a query run
 * on a page nobody came here to read, and the panel already has places that
 * answer those questions.
 */
const { t } = useTranslation()

const auth = useAuthStore()

const cards = [
    {
        to: '/admin/downloads',
        title: 'admin.downloads.title',
        body: 'admin.downloads.summary',
        ready: true,
    },
    { to: '/admin/news', title: 'admin.news.title', body: 'admin.news.summary', ready: true },
    { to: '/admin/wiki', title: 'admin.wiki.title', body: 'admin.wiki.summary', ready: true },
]
</script>

<template>
    <div>
        <h1 class="text-xl font-semibold tracking-tight">
            {{ t('admin.welcome', { name: auth.account?.username ?? '' }) }}
        </h1>
        <p class="mt-0.5 text-sm text-[var(--text-secondary)]">{{ t('admin.welcomeBody') }}</p>

        <ul class="mt-5 grid gap-3 sm:grid-cols-2">
            <li v-for="card in cards" :key="card.to">
                <component
                    :is="card.ready ? RouterLink : 'div'"
                    :to="card.ready ? card.to : undefined"
                    class="panel block h-full p-4"
                    :class="
                        card.ready
                            ? 'transition-colors hover:bg-[var(--surface-hover)]'
                            : 'opacity-60'
                    "
                >
                    <h2 class="font-medium">{{ t(card.title) }}</h2>
                    <p class="mt-1 text-sm text-[var(--text-secondary)]">{{ t(card.body) }}</p>
                    <p v-if="!card.ready" class="mt-2 text-[0.75rem] text-[var(--text-muted)]">
                        {{ t('admin.nav.soon') }}
                    </p>
                </component>
            </li>
        </ul>
    </div>
</template>
