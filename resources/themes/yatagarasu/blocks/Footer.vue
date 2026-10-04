<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import { useShell } from '@/composables/useShell'
import { useAuthStore } from '@/stores/auth'
import { useServerStore } from '@/stores/server'
import CrowMark from '../components/CrowMark.vue'
import OrnamentDivider from '../components/OrnamentDivider.vue'

/**
 * Yatagarasu — the footer.
 *
 * The brand and a sentence on the left, then three columns of links, an
 * ornament, and a three-part fine-print row.
 *
 * Only links that exist are rendered. The world column comes from useShell(),
 * which is the same list the navigation uses, so the two cannot drift apart;
 * the community column carries only what the operator configured, which is
 * why there is no row of social icons pointing nowhere; and the account column
 * is built from the signed-in state, because offering "Register" to somebody
 * already signed in is an invitation to a page that will bounce them.
 */
const { game, title, links: externalLinks } = useGame()
const { links } = useShell()
const auth = useAuthStore()
const servers = useServerStore()

const communityLabels: Record<string, string> = {
    discord: 'Discord',
    forum: 'Forum',
    website: 'Website',
    downloads: 'Downloads',
}

const community = computed(() =>
    externalLinks.value
        .filter(([key]) => key in communityLabels)
        .map(([key, url]) => ({ label: communityLabels[key], url })),
)

const accountLinks = computed(() =>
    auth.isAuthenticated
        ? [
              { label: 'My account', to: '/account' },
              { label: 'My characters', to: '/characters' },
              { label: 'Security', to: '/account/security' },
              { label: 'Account history', to: '/account/history' },
          ]
        : [
              { label: 'Create an account', to: '/register' },
              { label: 'Log in', to: '/sign-in' },
              { label: 'Forgot your password', to: '/forgot-password' },
              { label: 'Resend confirmation', to: '/resend-confirmation' },
          ],
)

const worldName = computed(() => servers.groups[0]?.name ?? null)
const year = new Date().getFullYear()
</script>

<template>
    <footer class="yata-foot">
        <div class="mx-auto max-w-7xl px-6 py-14">
            <div class="yata-foot__grid">
                <!-- The mark, and what this place is. -->
                <div class="yata-foot__brand">
                    <RouterLink to="/" class="mb-4 inline-flex items-center gap-3 no-underline">
                        <CrowMark :size="28" class="text-[var(--color-accent-500)]" />
                        <span class="yata-foot__wordmark">{{ title }}</span>
                    </RouterLink>

                    <p v-if="game.description" class="yata-prose text-sm text-[var(--text-muted)]">
                        {{ game.description }}
                    </p>

                    <p v-if="game.version" class="yata-figure mt-3 text-xs text-[var(--text-muted)]">
                        Version {{ game.version }}
                    </p>
                </div>

                <nav class="yata-foot__col" aria-labelledby="yata-foot-world">
                    <h2 id="yata-foot-world" class="yata-foot__heading">The World</h2>
                    <RouterLink
                        v-for="link in links"
                        :key="link.to"
                        :to="link.to"
                        class="yata-foot__link"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>

                <nav class="yata-foot__col" aria-labelledby="yata-foot-account">
                    <h2 id="yata-foot-account" class="yata-foot__heading">Account</h2>
                    <RouterLink
                        v-for="link in accountLinks"
                        :key="link.to"
                        :to="link.to"
                        class="yata-foot__link"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>

                <nav
                    v-if="community.length > 0"
                    class="yata-foot__col"
                    aria-labelledby="yata-foot-community"
                >
                    <h2 id="yata-foot-community" class="yata-foot__heading">Community</h2>
                    <a
                        v-for="link in community"
                        :key="link.url"
                        :href="link.url"
                        class="yata-foot__link"
                        rel="noreferrer noopener"
                    >
                        {{ link.label }}
                    </a>
                </nav>
            </div>

            <OrnamentDivider class="my-10 text-[var(--color-accent-500)]" />

            <div class="yata-foot__fine">
                <p class="yata-figure">&copy; {{ year }} {{ title }}. All rights reserved.</p>

                <!--
                    The disclaimer every independent server needs, written
                    without naming the trademark it disclaims — that name is
                    the operator's to state, and this theme must work for any
                    of them.
                -->
                <p class="yata-figure yata-foot__fine-mid">
                    An independent community server, not affiliated with or endorsed by the
                    publishers of the game it is based on.
                </p>

                <p v-if="worldName" class="yata-figure uppercase tracking-widest">
                    {{ worldName }}
                </p>
            </div>
        </div>
    </footer>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-foot {
    position: relative;
    z-index: 10;
    margin-top: 4rem;
    border-top: 1px solid var(--border-subtle);
    background-color: rgb(8 8 11 / 80%);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__grid {
    display: grid;
    gap: 2.5rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-foot__grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

/* The brand takes both columns on a phone, one on a wide window. */
:root[data-theme-slug='yatagarasu'] .yata-foot__brand {
    grid-column: span 2;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-foot__brand {
        grid-column: span 1;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-foot__wordmark {
    font-family: var(--yata-font-deco);
    font-size: 0.875rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__col {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.75rem;
}

:root[data-theme-slug='yatagarasu'] .yata-foot__heading {
    margin-bottom: 0.5rem;
    font-family: var(--font-display);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__link {
    font-family: var(--yata-font-body);
    font-size: 0.9375rem;
    color: var(--text-muted);
    text-decoration: none;
    transition: color 160ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__link:hover {
    color: var(--text-primary);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__fine {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1rem;
    font-size: 0.75rem;
    line-height: 1.6;
    text-align: center;
    color: var(--text-muted);
}

@media (min-width: 900px) {
    :root[data-theme-slug='yatagarasu'] .yata-foot__fine {
        flex-direction: row;
        align-items: baseline;
        justify-content: space-between;
        gap: 2rem;
        text-align: left;
    }

    :root[data-theme-slug='yatagarasu'] .yata-foot__fine-mid {
        max-width: 34rem;
        text-align: center;
    }
}
</style>
