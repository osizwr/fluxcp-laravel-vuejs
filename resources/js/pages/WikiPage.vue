<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import StateBlock from '../components/ui/StateBlock.vue'
import WikiIcon from '../components/ui/WikiIcon.vue'
import WikiSearch from '../components/ui/WikiSearch.vue'
import { useGame } from '../composables/useGame'
import { useWikiIndex, wikiDate, wikiRoute } from '../composables/useWiki'
import { useTranslation } from '../i18n'

/**
 * The wiki's front door.
 *
 * Everything on it comes from one request -- the sections, what is in them,
 * what changed recently -- because they are all the same question asked of
 * the same directory, and splitting it into four endpoints would mean four
 * scans of the same files.
 *
 * The page is deliberately a map rather than a reading surface: somebody
 * arriving here either knows what they want, in which case the search box is
 * the first thing under the title, or does not, in which case they need to
 * see the shape of the guide before they can ask for anything.
 */
const { t } = useTranslation()
const { game } = useGame()
const { index, loading, error, load } = useWikiIndex()

/** How many pages a section shows before it has to be asked for the rest. */
const PREVIEW = 4

/** Sections the visitor has expanded, by slug. */
const expanded = ref<Set<string>>(new Set())

function toggle(slug: string): void {
    const next = new Set(expanded.value)

    if (!next.delete(slug)) {
        next.add(slug)
    }

    expanded.value = next
}

const discord = computed(() => game.value.links.discord ?? null)

const empty = computed(() => index.value !== null && index.value.totals.pages === 0)

onMounted(load)
</script>

<template>
    <div class="pb-12">
        <!--
            The masthead. Drawn before the data arrives rather than after,
            so the search box is usable on a slow connection and the page
            does not change height under somebody who has started typing.
        -->
        <section class="mx-auto max-w-6xl px-4 pt-8 sm:pt-10">
            <div class="panel p-5 sm:p-8">
                <div class="gap-8 lg:flex lg:items-start lg:justify-between">
                    <div class="min-w-0 lg:max-w-2xl lg:flex-1">
                        <p
                            class="text-[0.75rem] font-semibold tracking-[0.14em] text-[var(--text-muted)] uppercase"
                        >
                            {{ game.name }}
                        </p>

                        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                            {{ t('wiki.title') }}
                        </h1>

                        <p class="mt-2 text-sm text-[var(--text-secondary)]">
                            {{ t('wiki.intro') }}
                            <template v-if="index && index.totals.pages > 0">
                                {{ t('wiki.pageCount', { count: index.totals.pages }) }}
                            </template>
                        </p>

                        <div class="mt-4">
                            <WikiSearch />
                        </div>

                        <div
                            v-if="index && index.popular.length > 0"
                            class="mt-3 flex flex-wrap items-center gap-2"
                        >
                            <span class="text-[0.8125rem] text-[var(--text-muted)]">
                                {{ t('wiki.popular') }}
                            </span>

                            <RouterLink
                                v-for="page in index.popular"
                                :key="page.path"
                                :to="wikiRoute(page.path)"
                                class="rounded-full border border-[var(--border-subtle)] px-3 py-1 text-[0.8125rem] font-medium hover:bg-[var(--surface-hover)]"
                            >
                                {{ page.title }}
                            </RouterLink>
                        </div>
                    </div>

                    <!--
                        The figures a visitor came to check. Absent entirely
                        until an operator configures them: the panel cannot
                        read rates out of rAthena, and an invented default
                        would be this page making a claim about somebody
                        else's server. See config/wiki.php.
                    -->
                    <aside
                        v-if="index && index.rates.length > 0"
                        class="mt-6 shrink-0 rounded-[var(--radius-panel)] border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-4 lg:mt-0 lg:w-80"
                        aria-labelledby="wiki-rates"
                    >
                        <h2
                            id="wiki-rates"
                            class="text-[0.75rem] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase"
                        >
                            {{ t('wiki.rates') }}
                        </h2>

                        <dl class="mt-3 grid grid-cols-2 gap-2">
                            <div
                                v-for="rate in index.rates"
                                :key="rate.label"
                                class="rounded-[var(--radius-panel)] bg-[var(--surface-raised)] px-3 py-2"
                            >
                                <dd class="text-lg font-semibold tabular-nums">{{ rate.value }}</dd>
                                <dt class="text-[0.75rem] text-[var(--text-secondary)]">
                                    {{ rate.label }}
                                </dt>
                                <dd
                                    v-if="rate.note"
                                    class="text-[0.6875rem] text-[var(--text-muted)]"
                                >
                                    {{ rate.note }}
                                </dd>
                            </div>
                        </dl>
                    </aside>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-4 pt-6">
            <StateBlock
                v-if="error"
                variant="error"
                :title="t('wiki.unavailable')"
                :description="t('wiki.unavailableBody')"
            />

            <StateBlock v-else-if="loading" variant="loading" :title="t('common.loading')" />

            <StateBlock
                v-else-if="empty"
                variant="empty"
                :title="t('wiki.empty')"
                :description="t('wiki.emptyBody')"
            />

            <div v-else-if="index" class="gap-5 lg:grid lg:grid-cols-4">
                <div class="grid content-start gap-4 sm:grid-cols-2 lg:col-span-3">
                    <article
                        v-for="category in index.categories"
                        :key="category.slug"
                        class="panel flex flex-col p-5"
                    >
                        <header class="flex items-start gap-3">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-[var(--radius-panel)] bg-[var(--surface-sunken)] text-[var(--color-accent-600)]"
                            >
                                <WikiIcon :name="category.icon" class="size-5" />
                            </span>

                            <div class="min-w-0">
                                <h2 class="font-semibold">{{ category.title }}</h2>
                                <p class="text-[0.75rem] text-[var(--text-muted)]">
                                    {{ t('wiki.pageCount', { count: category.count }) }}
                                </p>
                            </div>
                        </header>

                        <p
                            v-if="category.description"
                            class="mt-3 text-sm text-[var(--text-secondary)]"
                        >
                            {{ category.description }}
                        </p>

                        <ul class="mt-3 space-y-0.5 border-t border-[var(--border-subtle)] pt-3">
                            <li
                                v-for="page in expanded.has(category.slug)
                                    ? category.pages
                                    : category.pages.slice(0, PREVIEW)"
                                :key="page.path"
                            >
                                <RouterLink
                                    :to="wikiRoute(page.path)"
                                    class="-mx-2 flex items-center gap-1.5 rounded-[var(--radius-panel)] px-2 py-1.5 text-sm hover:bg-[var(--surface-hover)]"
                                >
                                    <svg
                                        class="size-3.5 shrink-0 text-[var(--text-muted)]"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path d="m9 18 6-6-6-6" />
                                    </svg>

                                    <span class="truncate">{{ page.title }}</span>
                                </RouterLink>
                            </li>
                        </ul>

                        <!--
                            A disclosure rather than a link to a section page,
                            because there is no section page: a directory is
                            not a document, and inventing one would mean a
                            page whose only content is the list already here.
                        -->
                        <button
                            v-if="category.count > PREVIEW"
                            type="button"
                            class="mt-2 self-start text-[0.8125rem] font-medium text-[var(--color-accent-600)] hover:underline"
                            :aria-expanded="expanded.has(category.slug)"
                            @click="toggle(category.slug)"
                        >
                            {{
                                expanded.has(category.slug)
                                    ? t('wiki.showFewer')
                                    : t('wiki.showMore', { count: category.count - PREVIEW })
                            }}
                        </button>
                    </article>
                </div>

                <aside class="mt-5 space-y-4 lg:mt-0">
                    <section
                        v-if="index.recent.length > 0"
                        class="panel p-5"
                        aria-labelledby="wiki-recent"
                    >
                        <h2 id="wiki-recent" class="font-semibold">{{ t('wiki.recent') }}</h2>

                        <ul class="mt-3 divide-y divide-[var(--border-subtle)]">
                            <li
                                v-for="page in index.recent"
                                :key="page.path"
                                class="py-2.5 first:pt-0 last:pb-0"
                            >
                                <RouterLink
                                    :to="wikiRoute(page.path)"
                                    class="text-sm font-medium text-[var(--color-accent-600)] hover:underline"
                                >
                                    {{ page.title }}
                                </RouterLink>

                                <p class="text-[0.75rem] text-[var(--text-muted)]">
                                    {{ page.category }}
                                    <template v-if="page.updated_at">
                                        · {{ wikiDate(page.updated_at) }}
                                    </template>
                                </p>
                            </li>
                        </ul>
                    </section>

                    <!--
                        Only when there is somewhere to send people. A card
                        reading "ask in our Discord" above no link is worse
                        than no card.
                    -->
                    <section v-if="discord" class="panel p-5" aria-labelledby="wiki-ask">
                        <h2 id="wiki-ask" class="font-semibold">{{ t('wiki.cantFind') }}</h2>

                        <p class="mt-1.5 text-sm text-[var(--text-secondary)]">
                            {{ t('wiki.cantFindBody') }}
                        </p>

                        <a
                            :href="discord"
                            target="_blank"
                            rel="noreferrer noopener"
                            class="mt-3 inline-block text-sm font-medium text-[var(--color-accent-600)] hover:underline"
                        >
                            {{ t('wiki.joinDiscord') }}
                        </a>
                    </section>
                </aside>
            </div>
        </section>
    </div>
</template>
