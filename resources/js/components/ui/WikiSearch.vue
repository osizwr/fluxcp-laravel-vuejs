<script setup lang="ts">
import { ref, useId } from 'vue'
import { useRouter } from 'vue-router'
import { useWikiSearch, wikiRoute } from '../../composables/useWiki'
import { useTranslation } from '../../i18n'

/**
 * The wiki's search box, and the results under it.
 *
 * Results are drawn over the page rather than pushing it down: somebody who
 * has started typing is looking at the box, and reflowing the section cards
 * below them while they do it moves the thing they were about to read.
 *
 * The searching is in useWikiSearch -- debounce, abort and all -- so the
 * landing page and an article's sidebar share one behaviour and not two
 * implementations of it.
 */
const props = withDefaults(defineProps<{ placeholder?: string; autofocus?: boolean }>(), {
    placeholder: undefined,
    autofocus: false,
})

const { t } = useTranslation()
const router = useRouter()

const { term, results, searching, searched, search, clear } = useWikiSearch()

/*
 * The label needs an id to point at, and a page may hold more than one of
 * these -- a theme is free to put a search box in the masthead as well as
 * in the page. Two inputs sharing an id would make both labels address the
 * first one, which is the kind of thing only somebody using a screen reader
 * finds out about.
 */
const id = useId()

/*
 * Results are hidden on blur rather than on click-away, which would need a
 * document listener. The delay is what lets the click that caused the blur
 * land on the link before it is removed.
 */
const open = ref(false)
let closing: ReturnType<typeof setTimeout> | null = null

function focus(): void {
    if (closing !== null) {
        clearTimeout(closing)
        closing = null
    }

    open.value = true
}

function blur(): void {
    closing = setTimeout(() => {
        open.value = false
    }, 150)
}

/**
 * Enter opens the first result, which is what somebody who typed the exact
 * title of a page and pressed return expects. With no results it does
 * nothing rather than submitting a form that does not exist.
 */
function submit(): void {
    const first = results.value[0]

    if (first !== undefined) {
        void router.push(wikiRoute(first.path))
        clear()
        open.value = false
    }
}

function dismiss(): void {
    clear()
    open.value = false
}

function go(): void {
    clear()
    open.value = false
}
</script>

<template>
    <div class="relative">
        <form role="search" @submit.prevent="submit">
            <label class="sr-only" :for="id">{{ t('wiki.searchLabel') }}</label>

            <div class="relative">
                <svg
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[var(--text-muted)]"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    aria-hidden="true"
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>

                <input
                    :id="id"
                    :value="term"
                    type="search"
                    class="field-input ps-9"
                    :placeholder="props.placeholder ?? t('wiki.searchPlaceholder')"
                    :autofocus="props.autofocus"
                    autocomplete="off"
                    @input="search(($event.target as HTMLInputElement).value)"
                    @focus="focus"
                    @blur="blur"
                    @keydown.esc="dismiss"
                />
            </div>
        </form>

        <!--
            Announced rather than only drawn, so a screen reader is told the
            list changed under a box the visitor is still typing in.
        -->
        <p class="sr-only" aria-live="polite">
            {{ searching ? t('wiki.searching') : t('wiki.resultCount', { count: results.length }) }}
        </p>

        <div
            v-if="open && term.trim().length >= 2"
            class="panel absolute inset-x-0 top-full z-30 mt-2 max-h-96 overflow-y-auto p-1.5 shadow-lg"
        >
            <p
                v-if="searching && results.length === 0"
                class="px-3 py-2.5 text-sm text-[var(--text-muted)]"
            >
                {{ t('wiki.searching') }}
            </p>

            <p
                v-else-if="searched && results.length === 0"
                class="px-3 py-2.5 text-sm text-[var(--text-muted)]"
            >
                {{ t('wiki.noResults', { term: term.trim() }) }}
            </p>

            <ul v-else class="space-y-0.5">
                <li v-for="result in results" :key="result.path">
                    <RouterLink
                        :to="wikiRoute(result.path)"
                        class="block rounded-[var(--radius-panel)] px-3 py-2 hover:bg-[var(--surface-hover)]"
                        @click="go"
                    >
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium">{{ result.title }}</span>
                            <span class="shrink-0 text-[0.6875rem] text-[var(--text-muted)]">
                                {{ result.category }}
                            </span>
                        </span>

                        <span
                            class="mt-0.5 line-clamp-2 block text-[0.8125rem] text-[var(--text-secondary)]"
                        >
                            {{ result.snippet }}
                        </span>
                    </RouterLink>
                </li>
            </ul>
        </div>
    </div>
</template>
