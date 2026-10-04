<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import { useShell } from '@/composables/useShell'
import { useAuthStore } from '@/stores/auth'
import CrowMark from '../components/CrowMark.vue'

/**
 * Yatagarasu — the footer.
 *
 * A full plinth rather than a line of small print: the mark and a sentence on
 * the left, then columns of links.
 *
 * Only links that exist are rendered. The page columns come from useShell(),
 * which is the same list the navigation uses, so the footer cannot drift out
 * of step with it; the community links are only those the operator
 * configured, which is why there is no row of social icons pointing nowhere.
 *
 * The account column is built from the signed-in state for the same reason —
 * offering "Register" to somebody already signed in is an invitation to a page
 * that will bounce them.
 */
const { game, title, links: externalLinks } = useGame()
const { links } = useShell()
const auth = useAuthStore()

/** The platform names the operator may have configured, in a fixed order. */
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

const year = new Date().getFullYear()
</script>

<template>
    <footer class="yata-foot">
        <hr class="yata-rule" />

        <div class="mx-auto max-w-7xl px-4 py-14">
            <div class="yata-foot__grid">
                <!-- The mark. -->
                <div class="yata-foot__brand">
                    <RouterLink to="/" class="inline-flex items-center gap-3 no-underline">
                        <CrowMark :size="30" class="text-[var(--color-accent-500)]" />
                        <span class="yata-foot__wordmark">{{ title }}</span>
                    </RouterLink>

                    <p v-if="game.description" class="yata-foot__blurb">
                        {{ game.description }}
                    </p>

                    <p v-if="game.version" class="yata-foot__version tabular">
                        Version {{ game.version }}
                    </p>
                </div>

                <nav class="yata-foot__col" aria-labelledby="yata-foot-game">
                    <h2 id="yata-foot-game" class="yata-foot__heading">The World</h2>
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

            <hr class="yata-rule my-10" />

            <div class="yata-foot__fine">
                <p>&copy; {{ year }} {{ title }}</p>
                <!--
                    The disclaimer every independent server needs, written
                    without naming the trademark it disclaims — that name is
                    the operator's to state, and this theme must work for any
                    of them.
                -->
                <p>
                    {{ title }} is an independent community server, not affiliated with or
                    endorsed by the publishers of the game it is based on. All trademarks are
                    the property of their respective owners.
                </p>
            </div>
        </div>
    </footer>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-foot {
    margin-top: 0;
    background-color: color-mix(in oklab, var(--yata-void) 70%, transparent);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__grid {
    display: grid;
    gap: 2.5rem;
    grid-template-columns: 1fr;
}

@media (min-width: 640px) {
    :root[data-theme-slug='yatagarasu'] .yata-foot__grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (min-width: 1024px) {
    /* The brand takes a wider first column; the link lists share the rest. */
    :root[data-theme-slug='yatagarasu'] .yata-foot__grid {
        grid-template-columns: 1.6fr repeat(3, minmax(0, 1fr));
        gap: 3rem;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-foot__wordmark {
    font-family: var(--font-display);
    font-size: 1.125rem;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__blurb {
    margin-top: 1.25rem;
    max-width: 26rem;
    font-size: 0.875rem;
    line-height: 1.7;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__version {
    margin-top: 0.75rem;
    font-size: 0.6875rem;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__col {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.75rem;
}

:root[data-theme-slug='yatagarasu'] .yata-foot__heading {
    font-family: var(--font-display);
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--color-accent-500);
    margin-bottom: 0.25rem;
}

:root[data-theme-slug='yatagarasu'] .yata-foot__link {
    font-size: 0.875rem;
    color: var(--text-secondary);
    text-decoration: none;
    transition: color 160ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__link:hover {
    color: var(--color-accent-300);
}

:root[data-theme-slug='yatagarasu'] .yata-foot__fine {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    font-size: 0.75rem;
    line-height: 1.6;
    color: var(--text-muted);
}

@media (min-width: 900px) {
    :root[data-theme-slug='yatagarasu'] .yata-foot__fine {
        flex-direction: row;
        align-items: baseline;
        justify-content: space-between;
        gap: 2rem;
    }

    :root[data-theme-slug='yatagarasu'] .yata-foot__fine p:last-child {
        max-width: 46rem;
        text-align: right;
    }
}
</style>
