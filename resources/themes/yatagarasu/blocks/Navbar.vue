<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import { useShell } from '@/composables/useShell'
import { useAuthStore } from '@/stores/auth'
import { useServerStore } from '@/stores/server'
import CrowMark from '../components/CrowMark.vue'

/**
 * Yatagarasu — navigation.
 *
 * All the behaviour comes from useShell(): which links exist, which is
 * current, and the sign-out sequence. This block decides only how that looks,
 * which is what lets a theme replace the navbar without reimplementing any of
 * it — and why there is no fetch, no guard and no route list here.
 *
 * Two states. Over the hero it is transparent, so the artwork runs to the top
 * of the window and the navigation appears to float on it. Once the page has
 * moved it becomes a solid dark band with a gold hairline, because white
 * capitals over whatever artwork happens to be beneath them is not legible at
 * any scroll position.
 */
const auth = useAuthStore()
const servers = useServerStore()
const { game, title } = useGame()
const { links, isActive, menuOpen, signingOut, signOut } = useShell()

/*
 * Past roughly the first screenful. Not a precise measurement — it only has
 * to be far enough that the bar has left the hero's top band.
 */
const lifted = ref(false)

function onScroll(): void {
    lifted.value = window.scrollY > 24
}

onMounted(() => {
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
})

onBeforeUnmount(() => window.removeEventListener('scroll', onScroll))
</script>

<template>
    <header class="yata-nav" :class="{ 'yata-nav--lifted': lifted || menuOpen }">
        <div class="mx-auto flex w-full max-w-7xl items-center gap-6 px-4">
            <!-- Brand. The operator's logo wins; the crow is the fallback. -->
            <RouterLink to="/" class="yata-nav__brand">
                <img
                    v-if="game.logo"
                    :src="game.logo"
                    :alt="title"
                    class="h-7 w-auto"
                    decoding="async"
                />
                <template v-else>
                    <CrowMark :size="26" class="text-[var(--color-accent-500)]" />
                    <span class="yata-nav__wordmark">{{ title }}</span>
                </template>
            </RouterLink>

            <!-- Centre: the world's sections. -->
            <nav class="yata-nav__links" aria-label="Primary">
                <RouterLink
                    v-for="link in links"
                    :key="link.to"
                    :to="link.to"
                    class="yata-nav__link"
                    :class="{ 'yata-nav__link--active': isActive(link.to) }"
                    :aria-current="isActive(link.to) ? 'page' : undefined"
                >
                    {{ link.label }}
                </RouterLink>
            </nav>

            <div class="ml-auto flex items-center gap-3">
                <!--
                    The live count, as a quiet figure rather than a pill. It is
                    the one piece of state the navbar carries, and it is fed by
                    the store the broadcast updates.
                -->
                <p v-if="servers.groups.length > 0" class="yata-nav__count">
                    <span
                        class="yata-nav__dot"
                        :class="servers.anyServerUp ? 'is-up' : 'is-down'"
                        aria-hidden="true"
                    />
                    <span class="tabular">
                        {{
                            servers.anyServerUp
                                ? `${servers.playersOnline.toLocaleString()} online`
                                : 'Offline'
                        }}
                    </span>
                </p>

                <template v-if="auth.isAuthenticated">
                    <RouterLink to="/account" class="yata-nav__link yata-nav__link--wide">
                        Account
                    </RouterLink>
                    <button
                        type="button"
                        class="yata-btn yata-btn--secondary yata-btn--compact yata-nav__cta"
                        :disabled="signingOut"
                        @click="signOut"
                    >
                        {{ signingOut ? 'Leaving…' : 'Sign out' }}
                    </button>
                </template>

                <template v-else>
                    <RouterLink to="/sign-in" class="yata-nav__link yata-nav__link--wide">
                        Log in
                    </RouterLink>
                    <RouterLink
                        to="/register"
                        class="yata-btn yata-btn--primary yata-btn--compact yata-nav__cta"
                    >
                        Play now
                    </RouterLink>
                </template>

                <!-- Mobile trigger. Three rules, not a hamburger glyph. -->
                <button
                    type="button"
                    class="yata-nav__toggle"
                    :aria-expanded="menuOpen"
                    aria-controls="yata-nav-menu"
                    @click="menuOpen = !menuOpen"
                >
                    <span class="sr-only">{{ menuOpen ? 'Close menu' : 'Open menu' }}</span>
                    <span class="yata-nav__bars" :class="{ 'is-open': menuOpen }" aria-hidden="true">
                        <i /><i /><i />
                    </span>
                </button>
            </div>
        </div>

        <!--
            The mobile panel. A full dark sheet rather than a dropdown: at this
            width the navigation is the page while it is open, and a translucent
            overlay over artwork is unreadable.
        -->
        <nav v-if="menuOpen" id="yata-nav-menu" class="yata-nav__sheet" aria-label="Primary">
            <RouterLink
                v-for="link in links"
                :key="link.to"
                :to="link.to"
                class="yata-nav__sheet-link"
                :class="{ 'yata-nav__link--active': isActive(link.to) }"
                :aria-current="isActive(link.to) ? 'page' : undefined"
                @click="menuOpen = false"
            >
                {{ link.label }}
            </RouterLink>

            <hr class="yata-rule my-3" />

            <template v-if="auth.isAuthenticated">
                <RouterLink to="/account" class="yata-nav__sheet-link" @click="menuOpen = false">
                    Account
                </RouterLink>
                <button
                    type="button"
                    class="yata-btn yata-btn--secondary mt-2 w-full"
                    :disabled="signingOut"
                    @click="signOut"
                >
                    {{ signingOut ? 'Leaving…' : 'Sign out' }}
                </button>
            </template>

            <template v-else>
                <RouterLink to="/sign-in" class="yata-nav__sheet-link" @click="menuOpen = false">
                    Log in
                </RouterLink>
                <RouterLink
                    to="/register"
                    class="yata-btn yata-btn--primary mt-2 w-full"
                    @click="menuOpen = false"
                >
                    Play now
                </RouterLink>
            </template>
        </nav>
    </header>
</template>

<style>
/*
 * `.yata-nav` is a flex container, which is why its inner row carries `w-full`
 * as well as `mx-auto`: an auto inline margin on a flex item cancels the
 * default cross-axis stretch, so without it the bar shrink-wraps to its
 * content and floats in the middle of the window instead of spanning it.
 */
:root[data-theme-slug='yatagarasu'] .yata-nav {
    position: sticky;
    top: 0;
    z-index: 40;
    min-height: 64px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    border-bottom: 1px solid transparent;
    transition:
        background-color 260ms var(--yata-ease),
        border-color 260ms var(--yata-ease),
        backdrop-filter 260ms var(--yata-ease);
}

/*
 * The scrolled state. Not fully opaque — a trace of the artwork behind keeps
 * the bar part of the page rather than a lid on it — but dark enough that
 * text on it is legible whatever is underneath.
 */
:root[data-theme-slug='yatagarasu'] .yata-nav--lifted {
    background-color: color-mix(in oklab, var(--yata-void) 88%, transparent);
    border-bottom-color: var(--yata-rule);
    backdrop-filter: blur(14px) saturate(130%);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__brand {
    display: inline-flex;
    align-items: center;
    gap: 0.625rem;
    text-decoration: none;
    flex-shrink: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__wordmark {
    font-family: var(--font-display);
    font-size: 1.0625rem;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__links {
    display: none;
    align-items: center;
    gap: 1.75rem;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-nav__links {
        display: flex;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-nav__link {
    position: relative;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: color-mix(in oklab, var(--yata-ivory) 72%, transparent);
    text-decoration: none;
    padding-block: 0.5rem;
    transition: color 180ms var(--yata-ease);
    white-space: nowrap;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__link:hover {
    color: var(--yata-ivory);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__link--active {
    color: var(--color-accent-300);
}

/*
 * The current item is marked with a rule and a diamond above it rather than a
 * underline below. It reads as a tab in a plate instead of a hyperlink.
 */
:root[data-theme-slug='yatagarasu'] .yata-nav__link--active::after {
    content: '';
    position: absolute;
    left: 50%;
    bottom: 0;
    width: 100%;
    height: 1px;
    translate: -50% 0;
    background: linear-gradient(90deg, transparent, var(--color-accent-500), transparent);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__count {
    display: none;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted);
    white-space: nowrap;
}

@media (min-width: 1024px) {
    :root[data-theme-slug='yatagarasu'] .yata-nav__count {
        display: inline-flex;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-nav__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__dot.is-up {
    background-color: var(--color-up);
    box-shadow: 0 0 8px color-mix(in oklab, var(--color-up) 60%, transparent);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__dot.is-down {
    background-color: var(--color-down);
}

/* A smaller control, for the bar only. */
:root[data-theme-slug='yatagarasu'] .yata-btn--compact {
    padding: 0.5rem 1rem;
    font-size: 0.6875rem;
    letter-spacing: 0.12em;
}

/*
 * Responsive visibility is done here rather than with Tailwind's `md:hidden`
 * and friends.
 *
 * Those utilities are a single class, and every rule in this file is scoped to
 * an attribute selector on :root, which outranks them -- so a `md:hidden` on
 * an element this stylesheet also gives a `display` to is silently ignored.
 * It is the one trap in scoping a theme this way, and the fix is to not mix
 * the two on the same element.
 */
:root[data-theme-slug='yatagarasu'] .yata-nav__toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: none;
    border: 1px solid var(--yata-rule);
    border-radius: var(--radius-panel);
    cursor: pointer;
}

/* The toggle is for narrow windows only; the links take over at md. */
@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-nav__toggle,
    :root[data-theme-slug='yatagarasu'] .yata-nav__sheet {
        display: none;
    }
}

/* A second label that only appears when there is room for it. */
:root[data-theme-slug='yatagarasu'] .yata-nav__link--wide {
    display: none;
}

@media (min-width: 1024px) {
    :root[data-theme-slug='yatagarasu'] .yata-nav__link--wide {
        display: inline-flex;
    }
}

/* The call to action is hidden on the narrowest windows, where the sheet
 * carries it instead. */
:root[data-theme-slug='yatagarasu'] .yata-nav__cta {
    display: none;
}

@media (min-width: 640px) {
    :root[data-theme-slug='yatagarasu'] .yata-nav__cta {
        display: inline-flex;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-nav__bars {
    display: flex;
    flex-direction: column;
    gap: 4px;
    width: 16px;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__bars i {
    display: block;
    height: 1.5px;
    background-color: var(--color-accent-400);
    transition: translate 200ms var(--yata-ease), opacity 200ms var(--yata-ease);
}

/* Collapses to a cross while open, so the control says what it will do. */
:root[data-theme-slug='yatagarasu'] .yata-nav__bars.is-open i:nth-child(1) {
    translate: 0 5.5px;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__bars.is-open i:nth-child(2) {
    opacity: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__bars.is-open i:nth-child(3) {
    translate: 0 -5.5px;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__sheet {
    padding: 0.5rem 1rem 1.25rem;
    background-color: color-mix(in oklab, var(--yata-void) 97%, transparent);
    border-top: 1px solid var(--yata-rule);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__sheet-link {
    display: block;
    padding: 0.75rem 0.25rem;
    font-size: 0.8125rem;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: color-mix(in oklab, var(--yata-ivory) 80%, transparent);
    text-decoration: none;
    border-bottom: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__sheet-link:hover {
    color: var(--color-accent-300);
}

@media (prefers-reduced-motion: reduce) {
    :root[data-theme-slug='yatagarasu'] .yata-nav,
    :root[data-theme-slug='yatagarasu'] .yata-nav__bars i {
        transition: none;
    }
}
</style>
