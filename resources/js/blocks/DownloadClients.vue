<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useDownloadsData } from './data'
import type { DownloadsProps } from './contracts'
import type { DownloadClient, DownloadMirror, DownloadPlatform } from '../types/bootstrap'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * The packages on offer, and where to get them.
 *
 * Entirely operator-authored, from config/game.php, because the panel cannot
 * know what this server ships or where it is hosted. Nothing is fetched and
 * nothing is inferred: a field nobody set is a line that is not drawn.
 *
 * A package with no mirror is still shown, marked as not yet available. That
 * is the state every install starts in, and a card that says "not yet" is more
 * use to the operator filling it in -- and more honest to a visitor -- than a
 * section that silently has one fewer thing in it.
 *
 * The card is the picture; everything written about a package lives in the
 * dialog behind it. That split is what lets the whole card be one control,
 * which is what a card that is mostly artwork reads as, and it holds the row
 * to one height whatever an operator wrote.
 */
const props = withDefaults(defineProps<DownloadsProps>(), { framed: true })

const downloads = useDownloadsData()

/* The defaults live here rather than in defineProps, which is hoisted out of
 * setup() and so cannot call t(). */
const headingText = computed(() => props.heading ?? t('downloads.clientsHeading'))

/**
 * The artwork behind each card, by platform.
 *
 * Three pictures rather than five: the desktop platforms share one, because
 * what it shows is somebody sitting at a computer and nothing in the scene is
 * Windows in particular. A platform missing from here -- or one the operator
 * named that this panel has no word for -- gets no art and falls back to the
 * plain panel, which is also what a server that emptied the folder gets.
 */
const ART: Partial<Record<DownloadPlatform, string>> = {
    windows: 'desktop',
    macos: 'desktop',
    linux: 'desktop',
    android: 'android',
    ios: 'ios',
}

/*
 * Art that failed to load, so it is asked for once rather than on every
 * render. The files ship with the panel, but public/images is exactly the
 * folder an operator reaches into, and a broken-image glyph behind the title
 * is worse than no picture at all.
 */
const missingArt = ref<ReadonlySet<string>>(new Set())

/**
 * The platform's name in the visitor's language.
 *
 * Null for a platform this panel has no word for, which is the same answer it
 * gives for one the operator did not name at all: the card simply omits it
 * rather than printing a raw key.
 */
function platformLabel(client: DownloadClient): string | null {
    if (client.platform === null) {
        return null
    }

    const key = `downloads.platforms.${client.platform}`
    const translated = t(key)

    return translated === key ? null : translated
}

/**
 * Platform, size, version and date as one line, with whatever nobody
 * configured left out rather than rendered as an empty gap.
 */
function metaFor(client: DownloadClient): string[] {
    return [
        platformLabel(client),
        client.size,
        client.version === null ? null : t('downloads.versionValue', { version: client.version }),
        client.updated === null ? null : t('downloads.updatedValue', { date: client.updated }),
    ].filter((part): part is string => part !== null && part !== '')
}

/**
 * The mark drawn in the middle of a card.
 *
 * Four marks for five platforms: the Apple one covers both of theirs. Linux
 * falls back to a drawing of a monitor, which is also what a platform this
 * panel does not recognise gets -- there is no Linux mark that reads as one
 * shape at this size, and a penguin beside three flat logos would not belong
 * to the same set.
 *
 * The first three are their owners' trademarks, drawn here for the one thing
 * nominative use allows: saying which platform a download is for.
 */
type DownloadLogo = 'windows' | 'android' | 'apple' | 'desktop'

const LOGO: Record<DownloadPlatform, DownloadLogo> = {
    windows: 'windows',
    macos: 'apple',
    linux: 'desktop',
    android: 'android',
    ios: 'apple',
}

/**
 * Which mark a mirror's button gets.
 *
 * Read from the host first and the label only as a fallback, because the host
 * is the fact and the label is whatever the operator felt like typing -- "GDrive",
 * "Google Drive", "Drive (EU)" all point at the same place, and so does a
 * label in a language this panel does not read. Anything that matches nothing
 * is a direct link, which is the honest answer for a file on the server's own
 * host and for a service nobody here has heard of alike.
 */
type MirrorMark = 'gdrive' | 'mediafire' | 'mega' | 'direct'

function mirrorMark(mirror: DownloadMirror): MirrorMark {
    let host = ''

    try {
        host = new URL(mirror.url).hostname.toLowerCase()
    } catch {
        /* A relative path, or something that is not a URL at all. */
    }

    const label = mirror.label.toLowerCase()

    if (host.includes('mediafire') || label.includes('mediafire')) {
        return 'mediafire'
    }

    if (host.includes('mega.') || label.startsWith('mega')) {
        return 'mega'
    }

    if (host.includes('drive.google') || host.includes('docs.google') || label.includes('drive')) {
        return 'gdrive'
    }

    return 'direct'
}

/** A package's art, named whether or not the file turned out to exist. */
function artName(client: DownloadClient): string | undefined {
    return client.platform === null ? undefined : ART[client.platform]
}

interface DownloadMirrorView extends DownloadMirror {
    mark: MirrorMark
}

interface DownloadCard {
    client: DownloadClient
    /** The picture behind the card and down the side of the dialog, or null. */
    art: string | null
    meta: string[]
    logo: DownloadLogo
    mirrors: DownloadMirrorView[]
}

/*
 * Everything a card draws, worked out once rather than from four calls spread
 * across the template -- the dialog needs the same answers about whichever
 * package is open, and this is what keeps the two in step.
 */
const cards = computed<DownloadCard[]>(() =>
    downloads.value.clients.map((client) => {
        const art = artName(client)

        return {
            client,
            art:
                art === undefined || missingArt.value.has(art)
                    ? null
                    : `/images/downloads/${art}.webp`,
            meta: metaFor(client),
            logo: client.platform === null ? 'desktop' : LOGO[client.platform],
            mirrors: client.mirrors.map((mirror) => ({ ...mirror, mark: mirrorMark(mirror) })),
        }
    }),
)

function onArtError(client: DownloadClient): void {
    const art = artName(client)

    if (art !== undefined) {
        missingArt.value = new Set(missingArt.value).add(art)
    }
}

/*
 * The open package, held by name rather than by object so that it survives the
 * card list being recomputed underneath it -- which is what happens the moment
 * a picture fails to load, with the dialog already on screen.
 *
 * Built on the native dialog element, which brings focus trapping, Escape to
 * dismiss, inertness of the page behind and the top layer for free. Each of
 * those is a thing a hand-rolled overlay gets subtly wrong.
 */
const activeName = ref<string | null>(null)
const active = computed(
    () => cards.value.find((card) => card.client.name === activeName.value) ?? null,
)

const dialog = ref<HTMLDialogElement | null>(null)

/* Drives the closing half of the animation; the rules are in app.css. */
const closing = ref(false)

/** How long `download-modal-out` runs. The two have to agree. */
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

function open(card: DownloadCard): void {
    activeName.value = card.client.name
}

watch(
    activeName,
    (name) => {
        const element = dialog.value

        if (element !== null && name !== null && !element.open) {
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
 * With motion turned down there is nothing to wait for, so it closes outright
 * rather than sitting there for a sixth of a second.
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
 * the other way round: without it a dismissal this component did not start
 * would leave it believing the dialog was still open, and the next click on
 * the same card would do nothing.
 */
function onClose(): void {
    if (exitTimer !== null) {
        clearTimeout(exitTimer)
        exitTimer = null
    }

    closing.value = false
    activeName.value = null
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
</script>

<template>
    <!--
        Framed, this is a card of its own: its own width, its own padding and
        its own panel. Unframed, it is markup the caller has already put
        inside one -- which is how the downloads page is a single white card
        rather than a masthead card with a second card under it.

        The packages read well on a card either way because they are
        full-bleed artwork rather than white panels, which a white card would
        wash out.
    -->
    <section
        v-if="cards.length > 0"
        :class="props.framed ? 'mx-auto max-w-6xl px-4 pt-6 pb-10' : 'mt-6'"
        aria-labelledby="block-download-clients"
    >
        <div :class="props.framed ? 'panel p-5 sm:p-8' : ''">
            <h2 id="block-download-clients" class="text-lg font-semibold">{{ headingText }}</h2>

            <p v-if="props.description" class="mt-0.5 text-sm text-[var(--text-secondary)]">
                {{ props.description }}
            </p>

            <!-- The operator's own notice about the files, above the files. -->
            <p
                v-if="downloads.notice"
                class="mt-3 rounded-[var(--radius-panel)] border border-[var(--border-strong)] bg-[var(--surface-sunken)] px-3 py-2.5 text-sm"
                role="status"
            >
                {{ downloads.notice }}
            </p>

            <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="card in cards"
                    :key="card.client.name"
                    class="download-card panel relative overflow-hidden"
                    :class="`download-card--${card.logo}`"
                >
                    <!--
                        Decoration, so it is hidden rather than described: the
                        button laid over it carries the package's name, and "a
                        knight at a desk" is not something a visitor choosing a
                        download needs read out to them.
                    -->
                    <img
                        v-if="card.art"
                        :src="card.art"
                        alt=""
                        aria-hidden="true"
                        class="download-card__art"
                        loading="lazy"
                        decoding="async"
                        @error="onArtError(card.client)"
                    />

                    <!--
                        White at rest and its own colour under the pointer, over a
                        picture that goes from washed to full. Nothing here is
                        read out: the mark repeats what the button below it already
                        says, and a card that announced "Apple" twice would be
                        worse for it.
                    -->
                    <span class="download-card__logo" aria-hidden="true">
                        <svg
                            v-if="card.logo === 'apple'"
                            class="download-card__mark"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                        >
                            <path
                                d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09ZM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25Z"
                            />
                        </svg>

                        <svg
                            v-else-if="card.logo === 'android'"
                            class="download-card__mark"
                            viewBox="0 0 24 24"
                        >
                            <path
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                                d="m6.9 6.6 2.5 3.8M17.1 6.6l-2.5 3.8"
                            />
                            <path
                                fill="currentColor"
                                fill-rule="evenodd"
                                d="M4.3 16.3a7.7 7.7 0 0 1 15.4 0H4.3Zm4.9-2.6a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm5.6 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
                            />
                        </svg>

                        <svg
                            v-else-if="card.logo === 'windows'"
                            class="download-card__mark"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                        >
                            <path
                                d="M3 5.1 10.3 4.1v7.05H3V5.1Zm0 13.8 7.3 1v-6.96H3v5.96Zm8.1 1.11L21 21.4V12.9h-9.9v7.11ZM11.1 3.99 21 2.6v8.55h-9.9V3.99Z"
                            />
                        </svg>

                        <svg
                            v-else
                            class="download-card__mark"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <rect x="2.5" y="4" width="19" height="12.5" rx="2" />
                            <path d="M12 16.5v4M8.5 20.5h7" />
                        </svg>
                    </span>

                    <!--
                        The card is the picture and the mark, so the control over
                        the top of it is the whole card. Everything the operator
                        configured -- the name, the version, the mirrors, the
                        caveats -- is in the dialog it opens.
                    -->
                    <button
                        type="button"
                        class="download-card__hit"
                        :aria-label="t('downloads.openDetails', { client: card.client.name })"
                        @click="open(card)"
                    ></button>
                </li>
            </ul>
        </div>

        <dialog
            ref="dialog"
            class="download-modal"
            :class="{ 'download-modal--closing': closing }"
            :aria-label="active === null ? undefined : active.client.name"
            @close="onClose"
            @cancel.prevent="requestClose"
            @click="onBackdrop"
        >
            <div v-if="active" class="download-modal__panel">
                <!--
                    The picture gets a column rather than being the panel's
                    background: behind two paragraphs and a stack of buttons it
                    would have to be dimmed until there was nothing left of it.
                -->
                <div class="download-modal__art">
                    <img
                        v-if="active.art"
                        :src="active.art"
                        alt=""
                        aria-hidden="true"
                        @error="onArtError(active.client)"
                    />
                </div>

                <div class="download-modal__body">
                    <button
                        type="button"
                        class="download-modal__close"
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

                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 pr-10">
                        <h3 class="text-xl font-semibold">{{ active.client.name }}</h3>

                        <span
                            v-if="active.client.badge"
                            class="rounded-full bg-[var(--status-up-bg)] px-2 py-0.5 text-[0.6875rem] font-semibold tracking-wide uppercase"
                        >
                            {{ active.client.badge }}
                        </span>
                    </div>

                    <p
                        v-if="active.meta.length > 0"
                        class="mt-1 text-[0.8125rem] text-[var(--text-muted)]"
                    >
                        {{ active.meta.join(' · ') }}
                    </p>

                    <p
                        v-if="active.client.description"
                        class="mt-3 text-sm text-[var(--text-secondary)]"
                    >
                        {{ active.client.description }}
                    </p>

                    <!--
                        Four across, two on a narrow window: a row of squares
                        that happened to be three wide would leave the fourth
                        mirror on a line of its own, and an operator who
                        configured two would get two tiles stranded at a
                        quarter of the width.
                    -->
                    <ul
                        v-if="active.mirrors.length > 0"
                        class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4"
                    >
                        <li v-for="mirror in active.mirrors" :key="mirror.url">
                            <a
                                :href="mirror.url"
                                target="_blank"
                                rel="noreferrer noopener"
                                :aria-label="
                                    t('downloads.downloadFrom', {
                                        client: active.client.name,
                                        mirror: mirror.label,
                                    })
                                "
                                class="download-mirror rounded-[var(--radius-panel)] border border-[var(--border-strong)] bg-[var(--surface-raised)] p-2 text-sm font-medium transition-colors hover:bg-[var(--surface-hover)]"
                            >
                                <!--
                                    The host's own mark, in its own colours, so
                                    a mirror is recognised before it is read.
                                    Hidden from the accessibility tree: the
                                    name under it says the same thing, and the
                                    link's own label says it a third time.
                                -->
                                <span class="download-mirror__mark" aria-hidden="true">
                                    <svg v-if="mirror.mark === 'gdrive'" viewBox="0 0 87.3 78">
                                        <path
                                            fill="#0066da"
                                            d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z"
                                        />
                                        <path
                                            fill="#00ac47"
                                            d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44a9.06 9.06 0 0 0-1.2 4.5h27.5z"
                                        />
                                        <path
                                            fill="#ea4335"
                                            d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z"
                                        />
                                        <path
                                            fill="#00832d"
                                            d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.2z"
                                        />
                                        <path
                                            fill="#2684fc"
                                            d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z"
                                        />
                                        <path
                                            fill="#ffba00"
                                            d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z"
                                        />
                                    </svg>

                                    <svg v-else-if="mirror.mark === 'mega'" viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10.5" fill="#d9272e" />
                                        <path
                                            fill="#fff"
                                            d="M5.6 6.9h2.1L12 12.1l4.3-5.2h2.1v10.2h-2.3v-6.4L12 15.6 7.9 10.7v6.4H5.6z"
                                        />
                                    </svg>

                                    <svg
                                        v-else-if="mirror.mark === 'mediafire'"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            fill="#1299f3"
                                            d="M13.3 1.6c.5 2.6-.3 4.3-1.9 6-1.9 2-3.1 3.4-3.1 5.6 0 1.7 1 3 2.5 3.6-.8-1.1-1-2.2-.4-3.4.5-1.1 1.6-1.9 2.4-3 .3 1.1.1 2-.4 3 1.6-.8 2.7-2.2 2.9-4 1.3 1.3 1.9 2.9 1.9 4.6 0 3.8-3 6.6-6.9 6.6S4.4 17.8 4.4 14c0-5 4.4-8.3 8.9-12.4Z"
                                        />
                                    </svg>

                                    <!--
                                        Nobody's logo, so it takes the tile's
                                        own text colour rather than a brand's.
                                    -->
                                    <svg
                                        v-else
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <path d="M12 3v11m0 0 4-4m-4 4-4-4" />
                                        <path d="M4 16.5v2.5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2.5" />
                                    </svg>
                                </span>

                                <!-- Wraps rather than truncates: a label is a
                                     host's name, and half of one is no use. -->
                                <span class="leading-tight text-balance">{{ mirror.label }}</span>
                            </a>
                        </li>
                    </ul>

                    <p
                        v-else
                        class="mt-5 rounded-[var(--radius-panel)] border border-dashed border-[var(--border-strong)] px-3 py-2 text-sm text-[var(--text-muted)]"
                    >
                        {{ t('downloads.unavailable') }}
                    </p>

                    <ul
                        v-if="active.client.notes.length > 0"
                        class="mt-3 grid gap-1 text-[0.8125rem] text-[var(--text-muted)]"
                    >
                        <li v-for="note in active.client.notes" :key="note">{{ note }}</li>
                    </ul>
                </div>
            </div>
        </dialog>
    </section>
</template>
