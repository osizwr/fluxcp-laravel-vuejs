<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useGame } from '../composables/useGame'
import { useAuthStore } from '../stores/auth'
import { useTranslation } from '../i18n'

/**
 * The shell around the dashboard.
 *
 * Deliberately not the application layout. That one carries the player
 * navigation -- characters, rankings, the cart -- and none of it is what
 * somebody who came here to fix a download link is looking for. This has one
 * list of sections and a way back out to the site.
 *
 * A theme may replace it at resources/themes/<slug>/layouts/AdminLayout.vue,
 * though most will not want to: the dashboard already inherits the skin's
 * colours through the semantic tokens, which is the part worth skinning.
 */
const { t } = useTranslation()
const { title } = useGame()

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

/**
 * The sections, in the order they are worked through.
 *
 * `ready: false` draws the item without making it a link. These are sections
 * this build does not have yet, and showing them greyed is more use to an
 * operator than leaving them out -- it answers "where do I edit the news?"
 * with "not here yet" rather than with silence.
 *
 * `children` is the other case: a section with more than one screen under it.
 * They are always drawn rather than unfolded on arrival, for the same reason
 * the pending items are drawn -- the list is how an operator finds out what
 * the dashboard can do, and a submenu that appears only once you are already
 * inside it cannot tell anybody anything.
 */
const sections = computed(() => [
    { to: '/admin', label: t('admin.nav.overview'), ready: true },
    { to: '/admin/downloads', label: t('admin.nav.downloads'), ready: true },
    { to: '/admin/news', label: t('admin.nav.news'), ready: true },
    {
        to: '/admin/wiki',
        label: t('admin.nav.wiki'),
        ready: true,
        /*
         * Sections before pages: it is the order the two are decided in, and
         * a page's address begins with its section's, so it is also the order
         * they read in.
         */
        children: [
            { to: '/admin/wiki/sections', label: t('admin.nav.wikiSections') },
            { to: '/admin/wiki/pages', label: t('admin.nav.wikiPages') },
        ],
    },
])

/**
 * Whether the current page belongs to a section, as opposed to being it.
 *
 * RouterLink's own exact-active class lights only the section's own page, so
 * editing an article at /admin/news/4 would leave nothing in the list
 * highlighted. Overview is excluded because every path begins with /admin and
 * it would otherwise be lit on all of them.
 *
 * Used for the submenu items too, which is why a parent and its child can be
 * lit at once: on /admin/wiki/sections/new both "you are in Wiki" and "you
 * are in Sections" are true, and the child's own page is the one that is not.
 */
function within(to: string): boolean {
    return to !== '/admin' && route.path.startsWith(`${to}/`)
}

async function signOut(): Promise<void> {
    await auth.logout()
    await router.push('/admin/login')
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-[var(--surface-sunken)]">
        <header class="border-b border-[var(--border-subtle)] bg-[var(--surface-raised)]">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 px-4 py-2.5">
                <RouterLink to="/admin" class="font-semibold tracking-tight">
                    {{ t('admin.title') }}
                </RouterLink>

                <!--
                    Which server this dashboard is pointed at. A panel fronting
                    one installation makes this redundant; a panel fronting two
                    makes it the most important line on the page.
                -->
                <span class="text-sm text-[var(--text-muted)]">{{ title }}</span>

                <div class="ml-auto flex items-center gap-3 text-sm">
                    <span v-if="auth.account" class="text-[var(--text-secondary)]">
                        {{ auth.account.username }}
                    </span>

                    <RouterLink
                        to="/"
                        class="text-[var(--text-secondary)] underline underline-offset-2 hover:text-[var(--text-primary)]"
                    >
                        {{ t('admin.viewSite') }}
                    </RouterLink>

                    <button
                        type="button"
                        class="text-[var(--text-secondary)] underline underline-offset-2 hover:text-[var(--text-primary)]"
                        @click="signOut"
                    >
                        {{ t('nav.signOut') }}
                    </button>
                </div>
            </div>
        </header>

        <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:flex-row">
            <!--
                A real <nav> with a list inside it, so the sections are one
                thing to skip past with a screen reader rather than four links
                loose in the page.
            -->
            <nav class="md:w-48 md:shrink-0" :aria-label="t('admin.nav.label')">
                <ul class="flex flex-wrap gap-1 md:flex-col">
                    <li v-for="section in sections" :key="section.to">
                        <!--
                            Marked current with aria-current as well as a
                            colour, which RouterLink does for us: the highlight
                            is the only thing saying which section you are in,
                            and a colour alone does not say it to everyone.
                        -->
                        <RouterLink
                            v-if="section.ready"
                            :to="section.to"
                            class="admin-nav-link"
                            :class="{ 'admin-nav-link--within': within(section.to) }"
                        >
                            {{ section.label }}
                        </RouterLink>

                        <span v-else class="admin-nav-link admin-nav-link--pending">
                            {{ section.label }}
                            <span class="admin-nav-soon">{{ t('admin.nav.soon') }}</span>
                        </span>

                        <!--
                            A nested list rather than four more links in the
                            same one, so a screen reader says that these two
                            belong to the item above them.
                        -->
                        <ul v-if="section.children" class="admin-nav-sublist">
                            <li v-for="child in section.children" :key="child.to">
                                <RouterLink
                                    :to="child.to"
                                    class="admin-nav-link admin-nav-link--child"
                                    :class="{ 'admin-nav-link--within': within(child.to) }"
                                >
                                    {{ child.label }}
                                </RouterLink>
                            </li>
                        </ul>
                    </li>
                </ul>
            </nav>

            <main id="main" class="min-w-0 flex-1">
                <slot />
            </main>
        </div>
    </div>
</template>
