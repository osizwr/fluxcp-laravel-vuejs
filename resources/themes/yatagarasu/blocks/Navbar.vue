<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import { useShell, type ShellLink } from '@/composables/useShell'
import { useAuthStore } from '@/stores/auth'
import { useServerStore } from '@/stores/server'
import CrowMark from '../components/CrowMark.vue'

/**
 * Yatagarasu — the navigation.
 *
 * Brand on the left, grouped links in the middle, the state of the world and
 * the account on the right. It sits directly beneath the ticker and sticks
 * there, so both bands stay on screen as the page moves.
 *
 * All the behaviour comes from useShell(): which links exist, which is
 * current, and the sign-out sequence. This block decides only how that looks,
 * which is why there is no route list, no guard and no fetch here.
 */
const auth = useAuthStore()
const servers = useServerStore()
const { game, title } = useGame()
const { links, isActive, menuOpen, signingOut, signOut } = useShell()

/**
 * Which group each route belongs under.
 *
 * Grouping is presentation — it says nothing about what exists — so it lives
 * here rather than in the application. It is keyed by path, and a link with no
 * entry renders at the top level, which means a route added to the application
 * later appears in the bar rather than disappearing from it.
 */
const groupFor: Record<string, string> = {
    '/rankings/level': 'Realm',
    '/who-is-online': 'Realm',
    '/characters': 'Realm',
    '/items': 'Database',
    '/monsters': 'Database',
}

/** The order groups appear in, once they have anything in them. */
const groupOrder = ['Realm', 'Database']

interface NavNode {
    label: string
    to?: string
    children?: ShellLink[]
}

const nav = computed<NavNode[]>(() => {
    const ungrouped: NavNode[] = []
    const grouped = new Map<string, ShellLink[]>()

    for (const link of links.value) {
        const group = groupFor[link.to]

        if (group === undefined) {
            ungrouped.push({ label: link.label, to: link.to })

            continue
        }

        const bucket = grouped.get(group) ?? []
        bucket.push(link)
        grouped.set(group, bucket)
    }

    /*
     * A group with one entry is not a group: a dropdown that opens onto a
     * single item is two clicks where one would do, so it is promoted back to
     * the top level.
     */
    const groups: NavNode[] = []

    for (const label of groupOrder) {
        const children = grouped.get(label)

        if (children === undefined || children.length === 0) {
            continue
        }

        groups.push(
            children.length === 1
                ? { label: children[0].label, to: children[0].to }
                : { label, children },
        )
    }

    return [...ungrouped, ...groups]
})

/** A group is current when any of its children is. */
function groupActive(node: NavNode): boolean {
    return node.to !== undefined
        ? isActive(node.to)
        : (node.children?.some((child) => isActive(child.to)) ?? false)
}

/**
 * The line under the brand.
 *
 * The world's own name where the operator has configured one, which is what
 * the reference shows; the short name otherwise, and nothing at all when that
 * is only the title repeated.
 */
const worldName = computed(() => {
    const configured = servers.groups[0]?.name

    if (typeof configured === 'string' && configured.trim() !== '') {
        return configured
    }

    return game.value.shortName.toLowerCase() === title.value.toLowerCase()
        ? null
        : game.value.shortName
})
</script>

<template>
    <header class="yata-nav">
        <nav
            class="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-3 lg:px-6"
            aria-label="Primary"
        >
            <!-- Brand. The operator's logo wins; the crow is the fallback. -->
            <RouterLink to="/" class="yata-nav__brand" @click="menuOpen = false">
                <img
                    v-if="game.logo"
                    :src="game.logo"
                    :alt="title"
                    class="h-9 w-auto"
                    decoding="async"
                />
                <CrowMark v-else :size="34" class="text-[var(--color-accent-500)]" />

                <span class="flex flex-col leading-none">
                    <span class="yata-nav__wordmark">{{ title }}</span>
                    <span v-if="worldName" class="yata-nav__world">{{ worldName }}</span>
                </span>
            </RouterLink>

            <!-- Centre: the sections of the world. -->
            <ul class="yata-nav__links">
                <li v-for="node in nav" :key="node.label" class="yata-nav__item">
                    <RouterLink
                        v-if="node.to"
                        :to="node.to"
                        class="yata-nav__link"
                        :class="{ 'is-active': groupActive(node) }"
                        :aria-current="isActive(node.to) ? 'page' : undefined"
                    >
                        {{ node.label }}
                    </RouterLink>

                    <template v-else>
                        <!--
                            A button rather than a link: the group itself goes
                            nowhere, and a link that does nothing is a trap for
                            anyone navigating by keyboard.
                        -->
                        <button
                            type="button"
                            class="yata-nav__link yata-nav__link--group"
                            :class="{ 'is-active': groupActive(node) }"
                        >
                            {{ node.label }}
                            <svg
                                class="yata-nav__chevron"
                                width="12"
                                height="12"
                                viewBox="0 0 24 24"
                                fill="none"
                                aria-hidden="true"
                            >
                                <path
                                    d="m6 9 6 6 6-6"
                                    stroke="currentColor"
                                    stroke-width="2.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </button>

                        <div class="yata-nav__menu">
                            <div class="yata-nav__menu-inner">
                                <RouterLink
                                    v-for="child in node.children"
                                    :key="child.to"
                                    :to="child.to"
                                    class="yata-nav__menu-link"
                                    :class="{ 'is-active': isActive(child.to) }"
                                    :aria-current="isActive(child.to) ? 'page' : undefined"
                                >
                                    {{ child.label }}
                                </RouterLink>
                            </div>
                        </div>
                    </template>
                </li>
            </ul>

            <!-- Right: the state of the world, then the account. -->
            <div class="yata-nav__aside">
                <p v-if="servers.groups.length > 0" class="yata-nav__status">
                    <span class="yata-nav__state">
                        <span
                            class="yata-nav__dot"
                            :class="servers.anyServerUp ? 'is-up' : 'is-down'"
                            aria-hidden="true"
                        />
                        <span :class="servers.anyServerUp ? 'is-up' : 'is-down'">
                            {{ servers.anyServerUp ? 'Online' : 'Offline' }}
                        </span>
                    </span>
                    <span class="yata-nav__players">
                        Players:
                        <span class="yata-nav__players-count">
                            {{ servers.playersOnline.toLocaleString() }}
                        </span>
                    </span>
                </p>

                <span class="yata-nav__divider" aria-hidden="true" />

                <div v-if="auth.isAuthenticated" class="yata-nav__item">
                    <button type="button" class="yata-btn yata-btn--secondary yata-btn--compact">
                        {{ auth.account?.username ?? 'Account' }}
                        <svg
                            class="yata-nav__chevron"
                            width="12"
                            height="12"
                            viewBox="0 0 24 24"
                            fill="none"
                            aria-hidden="true"
                        >
                            <path
                                d="m6 9 6 6 6-6"
                                stroke="currentColor"
                                stroke-width="2.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </button>

                    <div class="yata-nav__menu yata-nav__menu--right">
                        <div class="yata-nav__menu-inner">
                            <RouterLink to="/account" class="yata-nav__menu-link">
                                My account
                            </RouterLink>
                            <RouterLink to="/account/security" class="yata-nav__menu-link">
                                Security
                            </RouterLink>
                            <button
                                type="button"
                                class="yata-nav__menu-link"
                                :disabled="signingOut"
                                @click="signOut"
                            >
                                {{ signingOut ? 'Leaving…' : 'Sign out' }}
                            </button>
                        </div>
                    </div>
                </div>

                <RouterLink
                    v-else
                    to="/sign-in"
                    class="yata-btn yata-btn--secondary yata-btn--compact"
                >
                    Log in
                </RouterLink>
            </div>

            <!-- Mobile trigger. -->
            <button
                type="button"
                class="yata-nav__toggle"
                :aria-expanded="menuOpen"
                aria-controls="yata-nav-drawer"
                @click="menuOpen = !menuOpen"
            >
                <span class="sr-only">{{ menuOpen ? 'Close menu' : 'Open menu' }}</span>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                        v-if="menuOpen"
                        d="M18 6 6 18M6 6l12 12"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                    />
                    <path
                        v-else
                        d="M4 7h16M4 12h16M4 17h16"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                    />
                </svg>
            </button>
        </nav>

        <!-- Mobile drawer: groups become headings with their children indented. -->
        <div v-if="menuOpen" id="yata-nav-drawer" class="yata-nav__drawer">
            <div v-for="node in nav" :key="node.label">
                <RouterLink
                    v-if="node.to"
                    :to="node.to"
                    class="yata-nav__drawer-link"
                    :class="{ 'is-active': groupActive(node) }"
                    @click="menuOpen = false"
                >
                    {{ node.label }}
                </RouterLink>
                <p v-else class="yata-nav__drawer-heading">{{ node.label }}</p>

                <div v-if="node.children" class="yata-nav__drawer-children">
                    <RouterLink
                        v-for="child in node.children"
                        :key="child.to"
                        :to="child.to"
                        class="yata-nav__drawer-link yata-nav__drawer-link--child"
                        :class="{ 'is-active': isActive(child.to) }"
                        @click="menuOpen = false"
                    >
                        {{ child.label }}
                    </RouterLink>
                </div>
            </div>

            <div class="yata-nav__drawer-foot">
                <template v-if="auth.isAuthenticated">
                    <RouterLink
                        to="/account"
                        class="yata-btn yata-btn--secondary yata-btn--compact flex-1"
                        @click="menuOpen = false"
                    >
                        {{ auth.account?.username ?? 'Account' }}
                    </RouterLink>
                    <button
                        type="button"
                        class="yata-btn yata-btn--secondary yata-btn--compact"
                        :disabled="signingOut"
                        @click="signOut"
                    >
                        {{ signingOut ? 'Leaving…' : 'Sign out' }}
                    </button>
                </template>

                <RouterLink
                    v-else
                    to="/sign-in"
                    class="yata-btn yata-btn--secondary yata-btn--compact w-full"
                    @click="menuOpen = false"
                >
                    Log in
                </RouterLink>
            </div>
        </div>
    </header>
</template>

<style>
/*
 * Responsive visibility is done here rather than with Tailwind's `lg:flex` and
 * friends. Those utilities are a single class, and every rule in this theme is
 * scoped to an attribute selector on :root, which outranks them — so an
 * `lg:hidden` on an element this stylesheet also gives a `display` to is
 * silently ignored. It is the one trap in scoping a theme this way.
 */
:root[data-theme-slug='yatagarasu'] .yata-nav {
    position: sticky;
    /* Directly beneath the ticker, so both bands stay on screen. */
    top: var(--yata-ticker-height);
    z-index: 40;
    border-bottom: 1px solid var(--border-subtle);
    background-color: rgb(8 8 11 / 95%);
    backdrop-filter: blur(12px);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__brand {
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
    flex-shrink: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__wordmark {
    font-family: var(--yata-font-deco);
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__world {
    margin-top: 0.3rem;
    font-family: var(--font-display);
    font-size: 0.6rem;
    letter-spacing: 0.25em;
    text-transform: uppercase;
    color: var(--text-muted);
}

/* ---- Links ------------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-nav__links {
    display: none;
    align-items: center;
    gap: 1.5rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__item {
    position: relative;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__link {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.25rem 0;
    font-family: var(--font-display);
    font-size: 0.875rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    white-space: nowrap;
    color: var(--text-muted);
    text-decoration: none;
    background: none;
    border: 0;
    cursor: pointer;
    transition: color 200ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__link:hover,
:root[data-theme-slug='yatagarasu'] .yata-nav__link.is-active {
    color: var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__chevron {
    transition: rotate 200ms var(--yata-ease);
}

/* ---- Dropdowns ---------------------------------------------------------- */

/*
 * Opened on hover and on focus-within, so the keyboard reaches them. The
 * padding on the wrapper is deliberate: it bridges the gap between the trigger
 * and the panel, so the pointer can travel between them without the menu
 * closing underneath it.
 */
:root[data-theme-slug='yatagarasu'] .yata-nav__menu {
    position: absolute;
    top: 100%;
    left: 50%;
    translate: -50% 0;
    z-index: 50;
    min-width: 11rem;
    padding-top: 0.75rem;
    visibility: hidden;
    opacity: 0;
    transition:
        opacity 150ms var(--yata-ease),
        visibility 150ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__menu--right {
    left: auto;
    right: 0;
    translate: none;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__item:hover .yata-nav__menu,
:root[data-theme-slug='yatagarasu'] .yata-nav__item:focus-within .yata-nav__menu {
    visibility: visible;
    opacity: 1;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__item:hover .yata-nav__chevron,
:root[data-theme-slug='yatagarasu'] .yata-nav__item:focus-within .yata-nav__chevron {
    rotate: 180deg;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__menu-inner {
    border: 1px solid var(--border-subtle);
    background-color: var(--surface-raised);
    box-shadow: 0 10px 40px rgb(0 0 0 / 50%);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__menu-link {
    display: block;
    width: 100%;
    padding: 0.625rem 1rem;
    font-family: var(--font-display);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    text-align: left;
    white-space: nowrap;
    color: var(--text-muted);
    text-decoration: none;
    background: none;
    border: 0;
    cursor: pointer;
    transition:
        background-color 160ms var(--yata-ease),
        color 160ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__menu-link:hover {
    background-color: rgb(28 27 34 / 60%);
    color: var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__menu-link.is-active {
    background-color: rgb(201 153 58 / 15%);
    color: var(--color-accent-500);
}

/* ---- The aside ---------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-nav__aside {
    display: none;
    align-items: center;
    gap: 1rem;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__status {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.15rem;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__state {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-family: var(--yata-font-tech);
    font-size: 0.75rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__state .is-up {
    color: var(--color-up);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__state .is-down {
    color: var(--color-down);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    flex-shrink: 0;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__dot.is-up {
    background-color: var(--color-up);
    box-shadow: 0 0 6px var(--color-up);
    animation: yata-pulse 2s ease-in-out infinite;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__dot.is-down {
    background-color: var(--color-down);
}

@keyframes yata-pulse {
    50% {
        opacity: 0.45;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-nav__players {
    font-family: var(--yata-font-tech);
    font-size: 0.65rem;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__players-count {
    color: var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__divider {
    width: 1px;
    height: 2rem;
    background-color: var(--border-subtle);
}

/* ---- Mobile -------------------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-nav__toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem;
    color: var(--color-accent-500);
    background: none;
    border: 0;
    cursor: pointer;
}

@media (min-width: 1024px) {
    :root[data-theme-slug='yatagarasu'] .yata-nav__links,
    :root[data-theme-slug='yatagarasu'] .yata-nav__aside {
        display: flex;
    }

    :root[data-theme-slug='yatagarasu'] .yata-nav__toggle,
    :root[data-theme-slug='yatagarasu'] .yata-nav__drawer {
        display: none;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-nav__drawer {
    padding: 1.5rem;
    border-top: 1px solid var(--border-subtle);
    background-color: var(--surface-page);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__drawer > div + div {
    margin-top: 1rem;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__drawer-link {
    display: block;
    padding: 0.375rem 0;
    font-family: var(--font-display);
    font-size: 0.875rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--text-muted);
    text-decoration: none;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__drawer-link.is-active {
    color: var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__drawer-heading {
    padding: 0.375rem 0;
    font-family: var(--font-display);
    font-size: 0.875rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: rgb(201 153 58 / 80%);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__drawer-children {
    margin: 0.25rem 0 0 0.75rem;
    padding-left: 0.75rem;
    border-left: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-nav__drawer-link--child {
    font-size: 0.75rem;
    letter-spacing: 0.1em;
    padding: 0.25rem 0;
}

:root[data-theme-slug='yatagarasu'] .yata-nav__drawer-foot {
    display: flex;
    gap: 0.5rem;
    margin-top: 1.25rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--border-subtle);
}

@media (prefers-reduced-motion: reduce) {
    :root[data-theme-slug='yatagarasu'] .yata-nav__dot.is-up {
        animation: none;
    }

    :root[data-theme-slug='yatagarasu'] .yata-nav__menu,
    :root[data-theme-slug='yatagarasu'] .yata-nav__chevron {
        transition: none;
    }
}
</style>
