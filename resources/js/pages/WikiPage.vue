<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import StateBlock from '../components/ui/StateBlock.vue'
import WikiSearch from '../components/ui/WikiSearch.vue'
import { useGame } from '../composables/useGame'
import { useWikiIndex, wikiRoute } from '../composables/useWiki'
import { useTranslation } from '../i18n'
import { api } from '../services/api'
import type { WikiArticle } from '../types/api'

/**
 * The wiki's front door.
 *
 * Everything on it comes from one request -- the sections and what is in
 * them -- because they are the same question asked of the same directory,
 * and splitting it into several endpoints would mean several scans of the
 * same files. The payload also carries a recently-updated list and a set of
 * popular shortcuts, neither of which this page draws; both are left in the
 * response for a theme that wants them.
 *
 * The page is deliberately a map rather than a reading surface: somebody
 * arriving here either knows what they want, in which case the search box is
 * the first thing under the title, or does not, in which case they need to
 * see the shape of the guide before they can ask for anything.
 */
const { t } = useTranslation()
const { game, title } = useGame()
const { index, loading, error, load } = useWikiIndex()

/*
 * The picture behind a section card when the section has none of its own.
 *
 * One file, borrowed from the downloads page, and a placeholder rather than a
 * decision: the point of it is the treatment, not the drawing. A section gets
 * its own art by having one uploaded on its dashboard screen, which is where
 * the square it has to be is explained.
 */
const SECTION_ART = '/images/downloads/android.webp'

/**
 * The sections, as the tiles draw them.
 *
 * A tile is one link, so a section with nothing to link to is dropped rather
 * than drawn as a dead picture. The API already filters empty sections out,
 * which makes this belt and braces -- but the alternative is an index entry
 * that does nothing when clicked, and that is worth a line of code to rule
 * out.
 *
 * The first page is where a section opens, because there is no section page
 * to open instead: a directory is not a document, and inventing one would
 * mean a page whose only content is a list of the pages beneath it.
 */
const tiles = computed(() =>
    (index.value?.categories ?? []).flatMap((category) => {
        const first = category.pages[0]

        return first === undefined
            ? []
            : [
                  {
                      slug: category.slug,
                      title: category.title,
                      art: category.image ?? SECTION_ART,
                      to: wikiRoute(first.path),
                  },
              ]
    }),
)

const discord = computed(() => game.value.links.discord ?? null)

/* -------------------------------------------------------------------------- */
/* The section dialog                                                         */
/* -------------------------------------------------------------------------- */

/**
 * The open section, held by slug rather than by object so it survives the
 * index being replaced underneath it.
 *
 * Built on the native dialog element, which brings focus trapping, Escape to
 * dismiss, inertness of the page behind and the top layer for free -- each of
 * them a thing a hand-rolled overlay gets subtly wrong. The downloads page
 * opens its own dialog the same way; the two share their animation.
 */
const activeSlug = ref<string | null>(null)

const active = computed(
    () => index.value?.categories.find((category) => category.slug === activeSlug.value) ?? null,
)

const dialog = ref<HTMLDialogElement | null>(null)

/* Drives the closing half of the animation; the rules are in app.css. */
const closing = ref(false)

/** How long `app-modal-out` runs. The two have to agree. */
const EXIT_MS = 160

let exitTimer: ReturnType<typeof setTimeout> | null = null

/*
 * The page behind must not scroll under the dialog. The top layer stops it
 * being interactive but not being scrolled, and a backdrop that slides away
 * from the thing it is dimming looks broken.
 */
function lockScroll(locked: boolean): void {
    document.documentElement.classList.toggle('app-modal-open', locked)
}

function wantsMotion(): boolean {
    return !window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * A plain click on a tile opens the dialog; anything else is left alone.
 *
 * Capture phase, which is the whole trick: RouterLink's own handler is on the
 * same element in the bubble phase and would otherwise navigate before this
 * one ran. Preventing the default here is what makes the router's guard stand
 * down, because it skips any click already prevented.
 *
 * A modified or middle click is a request for a new tab, not for a dialog, so
 * it falls through untouched and the href does what it says.
 */
function onTileClick(event: MouseEvent, slug: string): void {
    if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.altKey ||
        event.shiftKey
    ) {
        return
    }

    event.preventDefault()
    openSection(slug)
}

function openSection(slug: string): void {
    activeSlug.value = slug

    /*
     * Every row shut, so the dialog opens on the section's table of contents
     * rather than part-way into one of its pages. Nothing is fetched until a
     * row is asked for, which is the other half of the same choice: opening
     * a section should cost one dialog, not one dialog and an article.
     */
    openPath.value = null
}

watch(
    activeSlug,
    (slug) => {
        const element = dialog.value

        if (element !== null && slug !== null && !element.open) {
            element.showModal()
            lockScroll(true)
        }
    },
    { flush: 'post' },
)

/**
 * Starts the exit animation, and closes the dialog once it has played.
 *
 * A timer rather than the animationend event, because a dialog that will not
 * close is a page the visitor has to reload: a stylesheet that drops the
 * animation would never fire that event, while the timer runs either way.
 */
function requestClose(): void {
    const element = dialog.value

    if (element === null || !element.open || closing.value) {
        return
    }

    if (!wantsMotion()) {
        element.close()

        return
    }

    closing.value = true
    exitTimer = setTimeout(() => element.close(), EXIT_MS)
}

/*
 * The dialog's own close event, so the state follows the dialog rather than
 * the other way round: without it a dismissal this component did not start --
 * Escape, most often -- would leave it believing the dialog was still open,
 * and the next click on the same tile would do nothing.
 */
function onClose(): void {
    if (exitTimer !== null) {
        clearTimeout(exitTimer)
        exitTimer = null
    }

    closing.value = false
    activeSlug.value = null
    openPath.value = null
    lockScroll(false)
}

/*
 * The backdrop is part of the dialog's own box, so a click that lands on the
 * element itself -- rather than on the panel inside it -- is a click outside.
 */
function onBackdrop(event: MouseEvent): void {
    if (event.target === dialog.value) {
        requestClose()
    }
}

onBeforeUnmount(() => {
    if (exitTimer !== null) {
        clearTimeout(exitTimer)
    }

    lockScroll(false)
})

/* -------------------------------------------------------------------------- */
/* The accordion inside it                                                    */
/* -------------------------------------------------------------------------- */

/** One page's body, once somebody has asked for it. */
type Panel = { state: 'loading' | 'ready' | 'failed'; html: string }

/**
 * Bodies by path, fetched on first open and then kept.
 *
 * The index carries titles and summaries but not bodies -- it is one scan of
 * the whole guide, and putting every page's HTML in it would make the front
 * page pay for all of them. So a row fetches its own page the first time it
 * is opened, and closing a row does not throw the answer away: reopening it
 * is free, which is what makes an accordion worth having over a list of
 * links.
 */
const panels = ref<Record<string, Panel>>({})

/** The one open row. Null when every row is shut. */
const openPath = ref<string | null>(null)

async function loadPage(path: string): Promise<void> {
    const held = panels.value[path]

    // Already here, or already on its way.
    if (held !== undefined && held.state !== 'failed') {
        return
    }

    panels.value = { ...panels.value, [path]: { state: 'loading', html: '' } }

    try {
        const response = await api.get<{ data: WikiArticle }>(`wiki/${path}`)

        panels.value = {
            ...panels.value,
            [path]: { state: 'ready', html: response.data.html },
        }
    } catch {
        panels.value = { ...panels.value, [path]: { state: 'failed', html: '' } }
    }
}

function togglePage(path: string): void {
    if (openPath.value === path) {
        openPath.value = null

        return
    }

    openPath.value = path
    void loadPage(path)
}

function panelId(path: string): string {
    return `wiki-panel-${path.replace(/[^a-z0-9]+/gi, '-')}`
}

function triggerId(path: string): string {
    return `wiki-trigger-${path.replace(/[^a-z0-9]+/gi, '-')}`
}

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
                    <!--
                        No measure cap on this column. The usual reason to put
                        one on a block of prose is that a long line is hard to
                        track back from, but there are two lines of it here and
                        the search box below wants the room -- so the column
                        takes whatever the rates panel beside it is not using,
                        and the whole width when there is no rates panel.
                    -->
                    <div class="min-w-0 lg:flex-1">
                        <p
                            class="text-[0.75rem] font-semibold tracking-[0.14em] text-[var(--text-muted)] uppercase"
                        >
                            {{ game.name }}
                        </p>

                        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                            {{ t('wiki.title') }}
                        </h1>

                        <!--
                            The server's name is interpolated rather than
                            written into the sentence, for the reason the rest
                            of the frontend reads it from useGame(): a locale
                            file is shared by every install, and a name typed
                            into one is correct here and wrong everywhere
                            else. Set GAME_NAME to change it.
                        -->
                        <p class="mt-2 text-sm text-[var(--text-secondary)]">
                            {{ t('wiki.intro', { game: title }) }}
                        </p>

                        <div class="mt-4">
                            <WikiSearch />
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

            <div v-else-if="index" class="space-y-5">
                <div class="grid content-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <!--
                        A picture with its name on it, and the whole tile is
                        the link -- the same block the download cards draw.

                        Square, so the grid reads as a sheet of equal things
                        rather than a column of different ones. Nothing else
                        is written on it: the page count, the blurb and the
                        list of pages were all answers to questions nobody
                        asks of an index, which is only ever asked "which of
                        these is the one I want".
                    -->
                    <!--
                        Still a link, with the section's first page as its
                        target, and the click is intercepted. A button would
                        have been simpler and worse: this way the tile can be
                        opened in a new tab, copied as a URL, and read out as
                        somewhere to go rather than as an unexplained control
                        -- and the dialog is what a plain click gets.
                    -->
                    <RouterLink
                        v-for="tile in tiles"
                        :key="tile.slug"
                        :to="tile.to"
                        aria-haspopup="dialog"
                        class="wiki-card panel relative grid aspect-square place-items-start p-5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent-600)]"
                        @click.capture="onTileClick($event, tile.slug)"
                    >
                        <!--
                            Decoration, so it is hidden rather than described:
                            the pill over it already names the section, and the
                            drawing says nothing a reader choosing one needs
                            read out to them.
                        -->
                        <img
                            :src="tile.art"
                            alt=""
                            aria-hidden="true"
                            class="wiki-card__art"
                            loading="lazy"
                            decoding="async"
                        />

                        <span class="wiki-card__pill">{{ tile.title }}</span>
                    </RouterLink>
                </div>

                <!--
                    One dialog, reused by whichever section is open. Rendered
                    only while there is one: a dialog in the markup from the
                    first paint is nine duplicate headings in the
                    accessibility tree before anybody has asked for any of
                    them.
                -->
                <dialog
                    v-if="active"
                    ref="dialog"
                    class="wiki-modal"
                    :class="closing ? 'wiki-modal--closing' : ''"
                    :aria-label="active.title"
                    @close="onClose"
                    @click="onBackdrop"
                >
                    <div class="wiki-modal__panel">
                        <button
                            type="button"
                            class="wiki-modal__close"
                            :aria-label="t('common.close')"
                            @click="requestClose"
                        >
                            <svg
                                class="size-4"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                                />
                            </svg>
                        </button>

                        <h2 class="pr-10 text-xl font-semibold">{{ active.title }}</h2>

                        <p
                            v-if="active.description"
                            class="mt-1.5 text-sm text-[var(--text-secondary)]"
                        >
                            {{ active.description }}
                        </p>

                        <div class="mt-5">
                            <div
                                v-for="page in active.pages"
                                :key="page.path"
                                class="wiki-accordion__item"
                                :class="openPath === page.path ? 'wiki-accordion__item--open' : ''"
                            >
                                <h3>
                                    <button
                                        :id="triggerId(page.path)"
                                        type="button"
                                        class="wiki-accordion__trigger"
                                        :aria-expanded="openPath === page.path"
                                        :aria-controls="panelId(page.path)"
                                        @click="togglePage(page.path)"
                                    >
                                        <span class="min-w-0">
                                            {{ page.title }}

                                            <span
                                                v-if="page.summary"
                                                class="block text-[0.8125rem] font-normal text-[var(--text-muted)]"
                                            >
                                                {{ page.summary }}
                                            </span>
                                        </span>

                                        <svg
                                            class="wiki-accordion__chevron size-4"
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
                                    </button>
                                </h3>

                                <!--
                                    Two elements, because the open and shut of
                                    it is a grid row going from 0fr to 1fr --
                                    which is what lets a panel of unknown
                                    height animate without anybody measuring
                                    it. The outer one is the row; the inner
                                    one is what gets clipped.

                                    It stays in the document while shut rather
                                    than being v-show'd away, because an
                                    element that is not there cannot animate
                                    back. `visibility` is what keeps the
                                    hidden copy out of the tab order and out
                                    of the accessibility tree meanwhile.
                                -->
                                <div
                                    :id="panelId(page.path)"
                                    role="region"
                                    :aria-labelledby="triggerId(page.path)"
                                    class="wiki-accordion__reveal"
                                >
                                    <div class="wiki-accordion__panel">
                                        <p
                                            v-if="panels[page.path]?.state === 'loading'"
                                            class="text-sm text-[var(--text-muted)]"
                                        >
                                            {{ t('common.loading') }}
                                        </p>

                                        <div v-else-if="panels[page.path]?.state === 'failed'">
                                            <p class="text-sm text-[var(--color-down)]">
                                                {{ t('wiki.unavailable') }}
                                            </p>

                                            <button
                                                type="button"
                                                class="mt-1.5 text-[0.8125rem] font-medium text-[var(--color-accent-600)] hover:underline"
                                                @click="loadPage(page.path)"
                                            >
                                                {{ t('common.retry') }}
                                            </button>
                                        </div>

                                        <!--
                                        The server rendered this from Markdown
                                        with raw HTML stripped, by the same
                                        renderer the news and the CMS pages go
                                        through. See ContentRenderer.
                                    -->
                                        <!-- eslint-disable vue/no-v-html -->
                                        <div
                                            v-else-if="panels[page.path]?.state === 'ready'"
                                            class="wiki-prose"
                                            v-html="panels[page.path]?.html"
                                        />
                                        <!-- eslint-enable vue/no-v-html -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </dialog>

                <!--
                    Under the sections rather than beside them, now that the
                    sections have the full width. Only when there is somewhere
                    to send people: a card reading "ask in our Discord" above
                    no link is worse than no card.
                -->
                <aside v-if="discord" class="panel p-5" aria-labelledby="wiki-ask">
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
                </aside>
            </div>
        </section>
    </div>
</template>
