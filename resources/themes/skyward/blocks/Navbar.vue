<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAccounts } from '@/composables/useAccounts'
import { useGame } from '@/composables/useGame'
import { useShell } from '@/composables/useShell'
import { useAuthStore } from '@/stores/auth'
import type { BlockAction } from '@/blocks/contracts'
import AccountMenu from '../components/AccountMenu.vue'
import AppearanceToggle from '../components/AppearanceToggle.vue'
import GameMark from '../components/GameMark.vue'
import LanguagePicker from '../components/LanguagePicker.vue'
import { useTranslation } from '@/i18n'

const { t } = useTranslation()

/**
 * Skyward — the masthead.
 *
 * A single white card: mark and wordmark, the navigation as rounded tabs, and
 * two actions at the far end -- one quiet, one loud. Nothing is engraved or
 * bordered; what separates it from the page is elevation, which is why it is
 * drawn as a card rather than a bar.
 *
 * It does not position itself. The theme's PublicLayout puts it in a floating
 * shell and blocks.css draws both the floating and the flush state, so this
 * same block renders as an ordinary masthead in the core AppLayout, where
 * there is no shell to float in.
 *
 * There is an appearance toggle, at the far end past the two actions. It is
 * there because this skin now has somewhere to go: a night palette and a
 * second hero loop, rather than a dimmed copy of the day one. It sits apart
 * from Log in and Create Account on purpose -- those two are what the
 * masthead is asking of the visitor, and a preference about the page is not a
 * third thing to do.
 *
 * Behaviour is entirely from useShell(), useGame() and useAccounts(). Nothing
 * here decides anything -- including which actions exist: the loud one points
 * at the download the operator configured, or at registration while
 * registration is open, and never at a page that would turn the visitor away.
 */
const auth = useAuthStore()

const { game, title } = useGame()
const { registrationEnabled } = useAccounts()
const { links, isActive, menuOpen } = useShell()

/*
 * Dismissing the menu.
 *
 * Needed now that it floats and was not before: a sheet that pushed the page
 * down was impossible to overlook, where one that hangs over the page can be
 * forgotten about while it covers what is underneath. Escape and a press
 * outside are what a visitor will already try.
 */
const header = ref<HTMLElement | null>(null)
const toggle = ref<HTMLElement | null>(null)

function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || !menuOpen.value) {
        return
    }

    menuOpen.value = false

    /*
     * Focus goes back to the control that opened it. Leaving it on a button
     * that has just been removed drops the caret to the top of the document,
     * and a keyboard visitor has to tab through the masthead again.
     */
    toggle.value?.focus()
}

function onPointerDown(event: Event): void {
    if (!menuOpen.value) {
        return
    }

    const target = event.target as Node | null

    if (target !== null && header.value?.contains(target) !== true) {
        menuOpen.value = false
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    document.addEventListener('pointerdown', onPointerDown, true)
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown)
    document.removeEventListener('pointerdown', onPointerDown, true)
})

/**
 * The one emphatic action, and only for a visitor who is not signed in yet.
 *
 * Downloads first, because a visitor who wants to play wants the client. With
 * no download configured it falls back to the nearest thing that does exist,
 * which is the account the operator is willing to let them make.
 *
 * Signed in, this slot belongs to AccountMenu instead, so there is no
 * authenticated case to answer for here. Nothing is lost by dropping Play Now
 * there: the download is a navigation entry of its own, and the rest of what
 * this pointed at -- characters, the account -- is what the menu opens onto.
 */
const playAction = computed<BlockAction>(() => {
    if (game.value.links.downloads) {
        return { label: t('nav.playNow'), href: game.value.links.downloads }
    }

    return registrationEnabled.value
        ? { label: t('nav.playNow'), to: '/register' }
        : { label: t('nav.playNow'), to: '/sign-in' }
})
</script>

<template>
    <header ref="header" class="sky-navbar">
        <div class="flex items-center gap-2 px-4 py-2.5 sm:px-6 sm:py-3">
            <!--
                The brand and the actions each take an equal share of whatever
                the navigation does not, which is what centres the navigation on
                the card rather than on the space left over after the brand.
                Centring it by hand -- absolute, translated half its width --
                would do the same until the two sides stopped being able to
                agree on a width, and then it would sit on top of one of them.
            -->
            <div class="flex min-w-0 flex-1 items-center">
                <RouterLink to="/" class="flex shrink-0 items-center gap-2.5">
                    <!--
                        A supplied logo gets more height than the generated badge
                        does. The badge is a 40x40 square and reads at any size; a
                        lockup is wide and has words drawn into it, so at the
                        badge's height it collapses into a smudge.
                    -->
                    <GameMark :size="game.logo ? 52 : 34" />
                    <!--
                        Only when the operator has not supplied a logo. A supplied
                        one is a lockup with the server's name drawn into it, so
                        setting the name beside it prints the word twice.
                    -->
                    <span
                        v-if="!game.logo"
                        class="max-w-[8rem] truncate font-[family-name:var(--font-display)] text-[0.95rem] leading-tight font-extrabold tracking-tight text-[var(--text-primary)] sm:max-w-none sm:text-base"
                    >
                        {{ title }}
                    </span>
                </RouterLink>
            </div>

            <nav :aria-label="t('nav.label')" class="hidden items-center gap-0.5 xl:flex">
                <!--
                    Three kinds of item, because the navigation is configuration:
                    a route, an outbound address, and a section the server has no
                    page for yet. The last is drawn in place but not as an anchor
                    -- the masthead shows the shape of the server, and a tab that
                    navigates nowhere is worse than one that plainly cannot be
                    pressed. Give the entry a 'to' or a 'url' in config/game.php
                    and it becomes a real link.
                -->
                <component
                    :is="link.inert ? 'span' : link.href ? 'a' : RouterLink"
                    v-for="link in links"
                    :key="link.to"
                    v-bind="
                        link.inert
                            ? {}
                            : link.href
                              ? { href: link.href, rel: 'noreferrer noopener', target: '_blank' }
                              : {
                                    to: link.to,
                                    'aria-current': isActive(link.to) ? 'page' : undefined,
                                }
                    "
                    class="rounded-[var(--sky-radius-control)] px-3 py-2 text-[0.8125rem] font-bold transition-colors"
                    :class="
                        isActive(link.to)
                            ? 'bg-[var(--surface-hover)] text-[var(--color-accent-600)]'
                            : link.inert
                              ? 'text-[var(--text-secondary)]'
                              : 'text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)]'
                    "
                >
                    {{ link.label }}
                </component>
            </nav>

            <!--
                No `min-w-0` on this side, deliberately. It would let the group
                shrink below its own contents, and `justify-end` then pushes the
                overflow *leftward* -- across the navigation, which is how a
                language written out in full ended up sitting on top of the
                links. Without it the group holds its width and the navigation
                gives up its centring instead, which is the right thing to lose
                when both cannot hold.
            -->
            <div class="flex flex-1 items-center justify-end gap-1.5 sm:gap-2">
                <!--
                    Only where the navigation itself fits. A language written
                    out in full is ~110px, and below this breakpoint the brand
                    lockup, Play Now and the menu button have already taken the
                    bar -- so it moves into the sheet rather than being
                    abbreviated back to a code.
                -->
                <LanguagePicker class="hidden xl:block" />

                <!--
                    Signed in, the emphatic slot is the visitor's own name and
                    what they can do with it; the quiet pill beside it goes with
                    it, because signing out moved into the menu and the name was
                    already being printed twice.
                -->
                <AccountMenu v-if="auth.isAuthenticated" @opened="menuOpen = false" />

                <template v-else>
                    <!--
                        The quiet action. An outline pill so that the pair reads
                        as one choice and one fallback, rather than two equal
                        options.
                    -->
                    <RouterLink
                        to="/sign-in"
                        class="hidden rounded-[var(--sky-radius-control)] border border-[var(--border-strong)] px-4 py-2 text-[0.8125rem] font-bold whitespace-nowrap text-[var(--text-primary)] transition-colors hover:bg-[var(--surface-hover)] sm:block"
                    >
                        {{ t('nav.logIn') }}
                    </RouterLink>

                    <!-- The loud one. -->
                    <component
                        :is="playAction.href ? 'a' : RouterLink"
                        v-bind="
                            playAction.href
                                ? {
                                      href: playAction.href,
                                      rel: 'noreferrer noopener',
                                      target: '_blank',
                                  }
                                : { to: playAction.to }
                        "
                        class="inline-flex items-center rounded-[var(--sky-radius-control)] bg-[var(--color-accent-600)] px-4 py-2 text-[0.8125rem] font-extrabold whitespace-nowrap text-white transition-colors hover:bg-[var(--color-accent-700)] sm:px-5"
                    >
                        {{ playAction.label }}
                    </component>
                </template>

                <!--
                    Past the actions, not among them, and outside the
                    signed-in branch above: which of Create Account and the
                    account menu is showing has nothing to do with whether
                    somebody wants the page dark, and a control that moved
                    when you signed in would be a control you had to find
                    twice.
                -->
                <AppearanceToggle />

                <button
                    ref="toggle"
                    type="button"
                    class="rounded-[var(--sky-radius-control)] p-2 text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-hover)] xl:hidden"
                    :aria-expanded="menuOpen"
                    aria-controls="skyward-nav"
                    :aria-label="t('nav.menu')"
                    @click="menuOpen = !menuOpen"
                >
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            fill-rule="evenodd"
                            d="M3 5.75A.75.75 0 013.75 5h12.5a.75.75 0 010 1.5H3.75A.75.75 0 013 5.75zm0 4.5A.75.75 0 013.75 9.5h12.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75zm0 4.5a.75.75 0 01.75-.75h12.5a.75.75 0 010 1.5H3.75a.75.75 0 01-.75-.75z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>
            </div>
        </div>

        <Transition name="sky-sheet">
            <nav
                v-if="menuOpen"
                id="skyward-nav"
                :aria-label="t('nav.label')"
                class="sky-navbar__sheet px-3 py-2 xl:hidden"
            >
                <!-- The same three kinds as the bar above. -->
                <component
                    :is="link.inert ? 'span' : link.href ? 'a' : RouterLink"
                    v-for="link in links"
                    :key="link.to"
                    v-bind="
                        link.inert
                            ? {}
                            : link.href
                              ? { href: link.href, rel: 'noreferrer noopener', target: '_blank' }
                              : {
                                    to: link.to,
                                    'aria-current': isActive(link.to) ? 'page' : undefined,
                                }
                    "
                    class="block rounded-[var(--sky-radius-control)] px-3 py-2.5 text-sm font-bold"
                    :class="
                        isActive(link.to)
                            ? 'bg-[var(--surface-hover)] text-[var(--color-accent-600)]'
                            : 'text-[var(--text-secondary)]'
                    "
                    @click="menuOpen = false"
                >
                    {{ link.label }}
                </component>

                <div class="mt-2 border-t border-[var(--border-subtle)] pt-2 xl:hidden">
                    <LanguagePicker class="w-full" />
                </div>

                <!--
                    Only the way in. Signing out is not repeated here: the
                    account menu is in the bar at every width, so a second copy
                    in the sheet would be the same control twice on the same
                    screen -- and the one in the sheet would be the one nobody
                    could see, because opening the sheet is what you do when you
                    have not found the control you wanted.
                -->
                <div
                    v-if="!auth.isAuthenticated"
                    class="mt-2 border-t border-[var(--border-subtle)] pt-2 sm:hidden"
                >
                    <RouterLink
                        to="/sign-in"
                        class="block rounded-[var(--sky-radius-control)] border border-[var(--border-strong)] px-4 py-2.5 text-center text-sm font-bold text-[var(--text-primary)]"
                        @click="menuOpen = false"
                    >
                        {{ t('nav.logIn') }}
                    </RouterLink>
                </div>
            </nav>
        </Transition>
    </header>
</template>
