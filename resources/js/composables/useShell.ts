import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useGame } from './useGame'
import { translate as t } from '../i18n'

export interface ShellLink {
    /**
     * The route to navigate to, and the stable key for a `v-for`.
     *
     * Always a string, so every existing consumer keeps working unchanged. For
     * an external item it holds the same address as `href`, and for one with no
     * destination a `#` placeholder derived from the label -- neither of which
     * matches a route, so `isActive` simply never reports them as current.
     */
    to: string
    label: string
    /** Set when the item leaves the site; render an anchor rather than a route. */
    href?: string
    /** Set when the section has no page yet; render it without an anchor. */
    inert?: boolean
}

/**
 * Everything the application shell needs to behave, with nothing about how it
 * looks.
 *
 * This exists so a theme's layout can be purely presentational. Without it,
 * every theme would copy the navigation list, the active-route matching and
 * the sign-out sequence, and the fourth theme would be the one that forgot to
 * clear the session properly.
 *
 * A theme consumes this and decides only what the shell looks like.
 */
export function useShell(): {
    links: ComputedRef<ShellLink[]>
    isActive: (to: string) => boolean
    menuOpen: Ref<boolean>
    signingOut: Ref<boolean>
    signOut: () => Promise<void>
} {
    const auth = useAuthStore()
    const { game } = useGame()
    const route = useRoute()
    const router = useRouter()

    const menuOpen = ref(false)
    const signingOut = ref(false)

    /**
     * Only routes that exist, and only those the viewer can reach.
     *
     * Built from the authenticated state rather than rendered-and-hidden, so
     * the markup never contains a link to a page that would bounce the
     * visitor.
     */
    const builtInLinks = computed<ShellLink[]>(() => [
        { to: '/', label: t('nav.status') },
        { to: '/rankings/level', label: t('nav.rankings') },
        { to: '/who-is-online', label: t('nav.online') },
        { to: '/items', label: t('nav.items') },
        { to: '/monsters', label: t('nav.monsters') },
        ...(auth.isAuthenticated
            ? [
                  { to: '/characters', label: t('nav.characters') },
                  { to: '/account', label: t('nav.account') },
              ]
            : []),
    ])

    /**
     * The masthead's links.
     *
     * An operator's configured navigation wins when there is one, because it
     * names the sections their server actually has. The built-in list below is
     * the fallback, and it is the better of the two where it applies: it is
     * translated, and every entry is a route this panel is known to serve.
     *
     * Both shapes come out of here rather than out of each theme, so switching
     * skins cannot change what the navigation offers.
     */
    /**
     * An operator's nav label, translated when the panel knows the word.
     *
     * The labels in `config/game.php` are the operator's own text, and most of
     * them are the same half-dozen entries every server has -- Home, Download,
     * Wiki. Those have translations; a label somebody invented for their own
     * server does not, and must survive untouched rather than being guessed at
     * or blanked.
     *
     * So the label is looked up by a key derived from itself, and the lookup
     * failing is the normal case rather than an error: `translate` returns the
     * key it was given when it finds nothing, which is the signal to keep the
     * operator's words.
     */
    function labelFor(label: string): string {
        const key =
            'nav.' +
            label
                .toLowerCase()
                .replace(/[^a-z0-9]+(.)?/g, (_, c: string | undefined) =>
                    c === undefined ? '' : c.toUpperCase(),
                )

        const translated = t(key)

        return translated === key ? label : translated
    }

    const links = computed<ShellLink[]>(() => {
        const configured = game.value.nav ?? []

        if (configured.length > 0) {
            return configured.map((link) => ({
                /*
                 * Always a path, never the outbound URL and never a bare
                 * fragment.
                 *
                 * That matters for the themes that have not been taught about
                 * `href` and `inert` yet: they render `<RouterLink :to>`
                 * regardless, and handing one an absolute URL makes the router
                 * resolve `/https://...`, while a `#fragment` resolves against
                 * whatever page the visitor is on. A path that simply has no
                 * route behind it degrades to this panel's own not-found page
                 * instead, which is the same thing a visitor would get from
                 * typing the address. It also keeps `v-for` keys unique and,
                 * matching no route, leaves `isActive` honest.
                 */
                to:
                    link.to ??
                    `/${link.label
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-|-$/g, '')}`,
                label: labelFor(link.label),
                ...(link.href === null ? {} : { href: link.href }),
                ...(link.to === null && link.href === null ? { inert: true } : {}),
            }))
        }

        return builtInLinks.value
    })

    function isActive(to: string): boolean {
        if (to === '/') {
            return route.path === '/'
        }

        // Matched on the first path segment so /rankings/zeny still marks the
        // Rankings item as current.
        return route.path.startsWith(to.split('/').slice(0, 2).join('/'))
    }

    async function signOut(): Promise<void> {
        signingOut.value = true

        try {
            await auth.logout()
            await router.push({ name: 'home' })
        } finally {
            signingOut.value = false
            menuOpen.value = false
        }
    }

    return { links, isActive, menuOpen, signingOut, signOut }
}
