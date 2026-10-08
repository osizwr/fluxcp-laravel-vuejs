<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { resolveBlock } from '@/theme/blocks'
import { currentAppearance } from '@/composables/useAppearance'
import { claimReveal, usePreload } from '@/composables/usePreload'
import { useAuthStore } from '@/stores/auth'
import AuthModal from '../components/AuthModal.vue'
import LoadingScreen from '../components/LoadingScreen.vue'
import { useTranslation } from '@/i18n'

const { t } = useTranslation()

/**
 * Skyward — the public shell.
 *
 * Replaces the core PublicLayout for one reason: the masthead and the ticker
 * are not stacked above the page here, they ride over it. At rest they are two
 * rounded cards floating on the hero's sky; once the visitor scrolls they snap
 * flush to the viewport edges and square off into a solid bar.
 *
 * The blocks themselves know nothing about any of that. They render a masthead
 * and a ticker; this layout decides they float, and blocks.css draws both
 * states. That is deliberate -- it means the same two blocks still render
 * correctly in the core AppLayout, where there is no floating shell and they
 * fall back to plain flush bars.
 *
 * It owns exactly two pieces of state, both presentational:
 *
 *   pinned   whether the page has been scrolled off the top
 *   height   how tall the shell is at rest
 *
 * Neither is data. This layout fetches nothing and decides nothing about what
 * a visitor may see; the blocks it mounts get their content from the same
 * composables every other theme's blocks use.
 */
const AnnouncementBar = resolveBlock('announcement-bar')
const Navbar = resolveBlock('navbar')
const Footer = resolveBlock('footer')

/*
 * The manifest, declared here rather than inside the loading screen so that
 * starting the preload happens in the parent, before any child reads its state.
 * What is worth gating on is the hero's video: it is the page's subject, and
 * everything else the landing page needs is already in the bundle.
 *
 * Only the page that shows the video waits for it. This shell is public-facing
 * rather than landing-page-specific -- the downloads page uses it too -- and
 * holding somebody who came for a client download behind forty megabytes of
 * hero they are never shown is the same toll this layout already refuses to
 * charge an account page.
 *
 * Decided from the route this page load entered on, because the first paint is
 * what a gate is for. Arrive on the front page and the video is fetched and
 * kept; arrive anywhere else and the hero streams its own source if the visitor
 * later navigates to it, which is what every block does when the preload was
 * skipped.
 */
const route = useRoute()

/*
 * Which loop to wait for, which is now a question with two answers.
 *
 * Read once, here, rather than watched: the preload is a once-per-page-load
 * event, and a visitor who switches to night while looking at the front page
 * should get the night loop streamed in behind them, not a loading screen
 * thrown back over a page they are already reading. The hero asks for the
 * other file directly in that case and the gate stays down.
 *
 * `currentAppearance()` rather than the composable's ref because this runs in
 * setup, before anything has mounted. Settling the preference is exactly what
 * that function is for.
 */
const heroMedia =
    route.name === 'home'
        ? [
              {
                  url:
                      currentAppearance() === 'dark'
                          ? '/videos/hero/skyward-night.mp4'
                          : '/videos/hero/skyward-hero.mp4',
              },
          ]
        : []

const { settled } = usePreload(heroMedia, { deadline: 20000 })

/*
 * The arrival fade, once. Claimed at setup so a later visit to this layout --
 * back from the rankings, say -- renders the page outright instead of fading it
 * in again, which would read as a reload that never happened.
 */
const revealing = claimReveal()

/* -------------------------------------------------------------------------- */
/* Signing in without leaving the page                                        */
/* -------------------------------------------------------------------------- */

const auth = useAuthStore()

const authView = ref<'sign-in' | 'register' | null>(null)

/**
 * Turns a click on any link to `/sign-in` or `/register` into the modal.
 *
 * Caught here, in the capture phase, rather than wired into each button. The
 * landing page offers those two destinations from the masthead, the hero and
 * the closing call to action, and the last of those is a core block this theme
 * does not override -- so wiring them individually would mean either overriding
 * a block to change one link, or shipping a page where two buttons open a modal
 * and the third navigates away.
 *
 * It also means the links *inside* the form work: "Need an account? Create
 * one" swaps the modal to registration instead of throwing the visitor out to a
 * page.
 *
 * Everything that would be rude to intercept is left alone. A modified click is
 * someone asking for a new tab, a non-primary button is not a navigation, and
 * an already-authenticated visitor is bounced off both routes by the router
 * guard anyway -- so for them the real page is the honest answer.
 */
function onNavigate(event: MouseEvent): void {
    if (event.defaultPrevented || event.button !== 0) {
        return
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return
    }

    const anchor = (event.target as Element | null)?.closest?.('a')

    if (!anchor || (anchor.getAttribute('target') ?? '_self') !== '_self') {
        return
    }

    const href = anchor.getAttribute('href')

    if ((href !== '/sign-in' && href !== '/register') || auth.isAuthenticated) {
        return
    }

    /*
     * Prevented, but deliberately not stopped.
     *
     * Vue Router's own listener bails out the moment it sees
     * `defaultPrevented`, so preventing is enough to stop the navigation --
     * and stopping propagation as well would kill every other handler on the
     * link. The mobile menu's "Log in" closes the menu that way, so halting the
     * capture phase left the sheet hanging open behind the modal.
     */
    event.preventDefault()

    authView.value = href === '/register' ? 'register' : 'sign-in'
}

const shell = ref<HTMLElement | null>(null)
const pinned = ref(false)

/*
 * Far enough down that the masthead does not flicker between states when a
 * trackpad drifts a pixel at the top of the page, close enough that the snap
 * still reads as a response to scrolling.
 */
const PIN_AFTER = 24

/*
 * The shell is `position: fixed`, so it is out of flow and content would start
 * underneath it. `.sky-main` is padded by this height and the hero cancels
 * exactly that padding, which is what lets the sky run behind the cards while
 * the hero's own text clears them.
 *
 * Measured rather than hardcoded: the ticker renders nothing when there is no
 * news, and a guessed height would then leave a band of empty sky above the
 * hero's text that nobody could account for.
 *
 * Only measured while at rest. Pinning collapses the shell's padding, so
 * tracking the height through that transition would drag the hero up the page
 * under a visitor who is in the middle of scrolling it.
 */
function measure(): void {
    if (shell.value === null || pinned.value) {
        return
    }

    const height = `${Math.round(shell.value.getBoundingClientRect().height)}px`

    document.documentElement.style.setProperty('--sky-shell-height', height)

    /*
     * The same figure under the core's neutral name, for anything that sticks
     * to the top of the viewport and would otherwise slide under the pinned
     * masthead -- the wiki's sidebar, for one. A page cannot reach for this
     * theme's own variable without being wrong under every other skin, so the
     * shell publishes the measurement rather than each page guessing at it.
     *
     * The at-rest height, which is the taller of the two states: pinning
     * collapses the shell's padding. Erring tall leaves a little daylight
     * under a pinned masthead, where erring short would tuck the first line
     * of a sidebar behind it.
     */
    document.documentElement.style.setProperty('--sticky-offset', height)
}

function onScroll(): void {
    const next = window.scrollY > PIN_AFTER

    if (next === pinned.value) {
        return
    }

    pinned.value = next

    // Unpinning restores the padding, so the at-rest height is measurable again.
    if (!next) {
        requestAnimationFrame(measure)
    }
}

let observer: ResizeObserver | null = null

onMounted(() => {
    measure()

    /*
     * The shell's height changes for reasons a scroll listener never sees: the
     * news arrives after the first paint, a long headline wraps the masthead on
     * a phone, the mobile menu opens. ResizeObserver covers all of them without
     * this layout having to know which blocks are inside it.
     */
    if (shell.value !== null && typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(measure)
        observer.observe(shell.value)
    }

    window.addEventListener('scroll', onScroll, { passive: true })
    onScroll()
})

onBeforeUnmount(() => {
    observer?.disconnect()
    window.removeEventListener('scroll', onScroll)

    // Another theme's layout must not inherit a height measured for this one.
    document.documentElement.style.removeProperty('--sky-shell-height')
    document.documentElement.style.removeProperty('--sticky-offset')
})
</script>

<template>
    <div class="flex min-h-screen flex-col bg-[var(--surface-page)]" @click.capture="onNavigate">
        <!--
            Covers the page until the hero's media is here. Mounted in the
            public shell only: it exists for the landing page's video, and
            gating an account page behind a download it does not use would be
            a toll for nothing.
        -->
        <LoadingScreen />

        <!--
            Outside the reveal wrapper: the dialog lives in the top layer, and
            nesting it inside an element that animates its opacity is asking
            for the two to argue about which one is painting.
        -->
        <AuthModal :view="authView" @close="authView = null" />

        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[60] focus:rounded focus:bg-[var(--surface-raised)] focus:px-3 focus:py-2 focus:text-sm"
        >
            {{ t('common.skipToContent') }}
        </a>

        <!--
            The page itself, which fades up as the gate clears.

            Wrapped rather than animated on the root, because the gate is a
            child of the root and animating that would fade the gate along with
            everything underneath it -- the two would cancel out and nothing
            would appear to happen.
        -->
        <div
            class="sky-reveal flex flex-1 flex-col"
            :class="{ 'sky-reveal--in': settled && revealing }"
        >
            <!--
            Masthead above ticker, which is the opposite of the core layout's
            order. The ticker is subordinate here: it labels itself NEWS and
            hangs below the masthead rather than sitting above it as a banner.
        -->
            <div ref="shell" class="sky-shell" :class="{ 'sky-shell--pinned': pinned }">
                <component :is="Navbar" v-if="Navbar" />
                <component :is="AnnouncementBar" v-if="AnnouncementBar" />
            </div>

            <main id="main" class="sky-main flex-1">
                <slot />
            </main>

            <component :is="Footer" v-if="Footer" />
        </div>
    </div>
</template>
