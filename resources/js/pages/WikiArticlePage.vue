<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import StateBlock from '../components/ui/StateBlock.vue'
import WikiIcon from '../components/ui/WikiIcon.vue'
import WikiSearch from '../components/ui/WikiSearch.vue'
import { api, ApiError } from '../services/api'
import { useWikiIndex, wikiDate, wikiRoute } from '../composables/useWiki'
import { useTranslation } from '../i18n'
import type { WikiArticle } from '../types/api'

/**
 * One wiki page.
 *
 * Three columns where there is room: the whole guide on the left so a reader
 * can see where they are in it, the page itself in the middle, and its own
 * headings on the right. Below that width the sidebar becomes a disclosure
 * and the headings go above the text, because a table of contents that has
 * pushed the article off the screen is not helping anybody.
 *
 * The body arrives as HTML the server rendered from Markdown with raw HTML
 * stripped -- see App\Services\Content\ContentRenderer, which is the same
 * renderer the news and the CMS pages go through.
 */
const { t } = useTranslation()
const route = useRoute()
const router = useRouter()

const { index, load: loadIndex } = useWikiIndex()

const article = ref<WikiArticle | null>(null)
const loading = ref(true)
const missing = ref(false)
const error = ref<string | null>(null)

/** The sidebar, on a screen too narrow to keep it open. */
const browsing = ref(false)

const path = computed(() => `${String(route.params.category)}/${String(route.params.page)}`)

const body = ref<HTMLElement | null>(null)

async function load(): Promise<void> {
    loading.value = true
    missing.value = false
    error.value = null

    try {
        const response = await api.get<{ data: WikiArticle }>(`wiki/${path.value}`)

        article.value = response.data
    } catch (caught) {
        article.value = null

        /*
         * A 404 is a page that does not exist, which is a normal thing for a
         * visitor to ask for -- a stale bookmark, a link in a two-year-old
         * Discord message -- and is answered with a way back rather than
         * with an error.
         */
        if (caught instanceof ApiError && caught.status === 404) {
            missing.value = true
        } else {
            error.value = t('wiki.unavailableBody')
        }
    } finally {
        loading.value = false
        browsing.value = false
    }
}

/**
 * Make the rendered body behave like part of the application.
 *
 * Two things the Markdown cannot say for itself:
 *
 *   - a link to another wiki page should navigate in place rather than
 *     reloading the whole application, and
 *   - a link that leaves the site should open in a new tab, and must carry
 *     `rel=noopener` when it does.
 *
 * Done to the DOM after rendering rather than by rewriting the HTML on the
 * server, because which links are "internal" is a question about where the
 * page is being served from, and the server would have to guess at that
 * behind a proxy.
 */
function adoptLinks(): void {
    const container = body.value

    if (container === null) {
        return
    }

    container.querySelectorAll('a[href]').forEach((anchor) => {
        const href = anchor.getAttribute('href') ?? ''

        // Same-page anchors are the table of contents and are left alone:
        // the browser's own scroll is what should happen.
        if (href.startsWith('#') || href.startsWith('/')) {
            return
        }

        if (/^https?:\/\//i.test(href)) {
            anchor.setAttribute('target', '_blank')
            anchor.setAttribute('rel', 'noreferrer noopener')
        }
    })
}

/**
 * Follow an internal link through the router.
 *
 * One listener on the container rather than a rewrite of every anchor: the
 * body is replaced wholesale on each navigation, and re-binding handlers to
 * its children each time is how one gets left behind.
 */
function intercept(event: MouseEvent): void {
    // Anything but a plain left click is the visitor asking for something
    // else -- a new tab, a saved link -- and is left to the browser.
    if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return
    }

    const target = event.target

    if (!(target instanceof Element)) {
        return
    }

    const anchor = target.closest('a')
    const href = anchor?.getAttribute('href') ?? ''

    if (anchor === null || !href.startsWith('/')) {
        return
    }

    event.preventDefault()
    void router.push(href)
}

watch(
    () => article.value?.html,
    () => {
        void nextTick(adoptLinks)
    },
)

// A different page under the same component: the route changed, the
// component did not, so the fetch has to be told.
watch(path, () => {
    void load()
})

onMounted(() => {
    void loadIndex()
    void load()
})
</script>

<template>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:py-8">
        <div
            class="lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-8 xl:grid-cols-[15rem_minmax(0,1fr)_13rem] xl:gap-10"
        >
            <!--
                The whole guide. Sticky on a tall screen so a reader can move
                between pages without scrolling back up for the list.
            -->
            <aside
                class="lg:sticky lg:top-[calc(var(--sticky-offset)+1.5rem)] lg:flex lg:max-h-[calc(100vh-var(--sticky-offset)-3rem)] lg:flex-col lg:self-start"
            >
                <!--
                    Outside the scrolling area on purpose. The search results
                    are drawn over the page, and an ancestor that scrolls
                    clips them -- so the column scrolls from the navigation
                    down rather than as a whole.
                -->
                <WikiSearch />

                <button
                    type="button"
                    class="mt-3 flex w-full items-center justify-between rounded-[var(--radius-panel)] border border-[var(--border-subtle)] px-3 py-2 text-sm font-medium lg:hidden"
                    :aria-expanded="browsing"
                    @click="browsing = !browsing"
                >
                    {{ t('wiki.browse') }}

                    <svg
                        class="size-4 text-[var(--text-muted)]"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path :d="browsing ? 'm18 15-6-6-6 6' : 'm6 9 6 6 6-6'" />
                    </svg>
                </button>

                <nav
                    v-if="index"
                    class="mt-3 lg:block lg:min-h-0 lg:flex-1 lg:overflow-y-auto"
                    :class="browsing ? 'block' : 'hidden'"
                    :aria-label="t('wiki.title')"
                >
                    <div v-for="category in index.categories" :key="category.slug" class="mb-5">
                        <p
                            class="flex items-center gap-2 px-2 text-[0.6875rem] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase"
                        >
                            <WikiIcon :name="category.icon" class="size-3.5" />
                            {{ category.title }}
                        </p>

                        <ul class="mt-1.5 space-y-0.5">
                            <li v-for="entry in category.pages" :key="entry.path">
                                <RouterLink
                                    :to="wikiRoute(entry.path)"
                                    class="block rounded-[var(--radius-panel)] px-2 py-1.5 text-[0.8125rem] hover:bg-[var(--surface-hover)]"
                                    :class="
                                        entry.path === path
                                            ? 'bg-[var(--surface-sunken)] font-semibold text-[var(--color-accent-600)]'
                                            : 'text-[var(--text-secondary)]'
                                    "
                                    :aria-current="entry.path === path ? 'page' : undefined"
                                >
                                    {{ entry.title }}
                                </RouterLink>
                            </li>
                        </ul>
                    </div>
                </nav>
            </aside>

            <main class="min-w-0 pt-6 lg:pt-0">
                <StateBlock
                    v-if="error"
                    variant="error"
                    :title="t('wiki.unavailable')"
                    :description="error"
                />

                <StateBlock v-else-if="loading" variant="loading" :title="t('common.loading')" />

                <StateBlock
                    v-else-if="missing"
                    variant="empty"
                    :title="t('wiki.missing')"
                    :description="t('wiki.missingBody')"
                >
                    <template #action>
                        <RouterLink
                            to="/wiki"
                            class="text-sm font-medium text-[var(--color-accent-600)] hover:underline"
                        >
                            {{ t('wiki.backToIndex') }}
                        </RouterLink>
                    </template>
                </StateBlock>

                <article v-else-if="article">
                    <nav
                        class="text-[0.8125rem] text-[var(--text-muted)]"
                        :aria-label="t('wiki.breadcrumb')"
                    >
                        <RouterLink to="/wiki" class="hover:underline">{{
                            t('wiki.title')
                        }}</RouterLink>

                        <template v-if="article.category">
                            <span aria-hidden="true"> / </span>
                            <span>{{ article.category.title }}</span>
                        </template>
                    </nav>

                    <h1 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">
                        {{ article.title }}
                    </h1>

                    <p
                        v-if="article.summary"
                        class="mt-2 text-[0.9375rem] text-[var(--text-secondary)]"
                    >
                        {{ article.summary }}
                    </p>

                    <p class="mt-2 text-[0.75rem] text-[var(--text-muted)]">
                        <template v-if="article.updated_at">
                            {{ t('wiki.updated', { date: wikiDate(article.updated_at) }) }} ·
                        </template>
                        {{ t('wiki.readingTime', { count: article.reading_minutes }) }}
                    </p>

                    <!--
                        On a narrow screen the contents go here, above the
                        text, because the column that holds them on a wide
                        one does not exist.
                    -->
                    <nav
                        v-if="article.headings.length > 1"
                        class="mt-5 rounded-[var(--radius-panel)] border border-[var(--border-subtle)] p-4 xl:hidden"
                        :aria-label="t('wiki.onThisPage')"
                    >
                        <p
                            class="text-[0.6875rem] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase"
                        >
                            {{ t('wiki.onThisPage') }}
                        </p>

                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="heading in article.headings"
                                :key="heading.id"
                                :class="heading.level === 3 ? 'ps-3' : ''"
                            >
                                <a
                                    :href="`#${heading.id}`"
                                    class="text-[0.8125rem] hover:underline"
                                >
                                    {{ heading.text }}
                                </a>
                            </li>
                        </ul>
                    </nav>

                    <!--
                        The body is Markdown the server rendered with raw HTML
                        stripped and unsafe link schemes refused, by the same
                        renderer the news and the CMS pages use. Interpolating
                        it as text instead would render the markup visibly and
                        defeat the point of a wiki; the safety is in what the
                        server produced, not in how it is inserted. See
                        docs/MIGRATION_DECISIONS.md (D20).
                    -->
                    <!-- eslint-disable vue/no-v-html -->
                    <div
                        ref="body"
                        class="wiki-prose mt-6"
                        @click="intercept"
                        v-html="article.html"
                    />
                    <!-- eslint-enable vue/no-v-html -->

                    <nav
                        v-if="article.previous || article.next"
                        class="mt-10 grid gap-3 border-t border-[var(--border-subtle)] pt-5 sm:grid-cols-2"
                        :aria-label="t('wiki.continue')"
                    >
                        <RouterLink
                            v-if="article.previous"
                            :to="wikiRoute(article.previous.path)"
                            class="panel p-4 hover:bg-[var(--surface-hover)]"
                        >
                            <span
                                class="block text-[0.6875rem] tracking-[0.1em] text-[var(--text-muted)] uppercase"
                            >
                                {{ t('common.previous') }}
                            </span>
                            <span class="mt-0.5 block font-medium">{{
                                article.previous.title
                            }}</span>
                        </RouterLink>

                        <RouterLink
                            v-if="article.next"
                            :to="wikiRoute(article.next.path)"
                            class="panel p-4 text-end hover:bg-[var(--surface-hover)] sm:col-start-2"
                        >
                            <span
                                class="block text-[0.6875rem] tracking-[0.1em] text-[var(--text-muted)] uppercase"
                            >
                                {{ t('common.next') }}
                            </span>
                            <span class="mt-0.5 block font-medium">{{ article.next.title }}</span>
                        </RouterLink>
                    </nav>
                </article>
            </main>

            <!-- The page's own headings, where the screen is wide enough to hold them. -->
            <aside
                v-if="article && article.headings.length > 1"
                class="hidden xl:sticky xl:top-[calc(var(--sticky-offset)+1.5rem)] xl:block xl:max-h-[calc(100vh-var(--sticky-offset)-3rem)] xl:self-start xl:overflow-y-auto"
            >
                <p
                    class="text-[0.6875rem] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase"
                >
                    {{ t('wiki.onThisPage') }}
                </p>

                <ul class="mt-2 space-y-1.5 border-s border-[var(--border-subtle)]">
                    <li
                        v-for="heading in article.headings"
                        :key="heading.id"
                        :class="heading.level === 3 ? 'ps-6' : 'ps-3'"
                    >
                        <a
                            :href="`#${heading.id}`"
                            class="block text-[0.8125rem] text-[var(--text-secondary)] hover:text-[var(--color-accent-600)]"
                        >
                            {{ heading.text }}
                        </a>
                    </li>
                </ul>
            </aside>
        </div>
    </div>
</template>
