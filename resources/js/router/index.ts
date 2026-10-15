import {
    createRouter,
    createWebHistory,
    type RouteLocationNormalized,
    type RouteLocationRaw,
    type RouteRecordRaw,
} from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { bootstrap } from '../theme/bootstrap'
import { themedRoute } from '../theme/resolve'
import { translate } from '../i18n'

/**
 * Client routes.
 *
 * `requiresAuth` and `guestOnly` only decide what to render. They are not
 * security: every endpoint behind these pages is authorised independently on
 * the server, because a route guard is trivially bypassed by anyone willing to
 * open a console.
 */
const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'home',
        component: themedRoute('home', 'HomePage', () => import('../pages/HomePage.vue')),
        // The front page is public-facing, so it uses the public shell by
        // default. A theme's composition may override the role per page.
        meta: { title: 'routes.home', layout: 'public' },
    },
    {
        /*
         * Public-facing, like the front page: somebody looking for the client
         * has not signed in yet, and most of the time never will before they
         * download it.
         *
         * Stays routed even when GAME_DOWNLOADS_URL points the navigation at a
         * CDN instead. An operator who later clears that variable gets the
         * page back without a deploy, and a link somebody bookmarked in the
         * meantime still resolves.
         */
        path: '/downloads',
        name: 'downloads',
        component: themedRoute(
            'downloads',
            'DownloadsPage',
            () => import('../pages/DownloadsPage.vue'),
        ),
        meta: { title: 'routes.downloads', layout: 'public' },
    },
    {
        /*
         * The wiki. Public, and on the public shell rather than the
         * application one: most of the people reading it do not have an
         * account yet, which is frequently what they are reading about.
         */
        path: '/wiki',
        name: 'wiki',
        component: themedRoute('wiki', 'WikiPage', () => import('../pages/WikiPage.vue')),
        meta: { title: 'routes.wiki', layout: 'public' },
    },
    {
        /*
         * One page, addressed exactly as it is laid out on disk: a section
         * and a page within it.
         *
         * Spelled as two constrained segments rather than as a catch-all so
         * that `/wiki/anything/else/deeper` falls through to the not-found
         * page instead of being requested from an endpoint that cannot
         * answer it. The constraint matches the route on the server.
         */
        path: '/wiki/:category([a-z0-9-]+)/:page([a-z0-9-]+)',
        name: 'wiki-page',
        component: themedRoute(
            'wiki-page',
            'WikiArticlePage',
            () => import('../pages/WikiArticlePage.vue'),
        ),
        meta: { title: 'routes.wiki', layout: 'public' },
    },
    {
        path: '/sign-in',
        name: 'login',
        component: themedRoute('login', 'LoginPage', () => import('../pages/LoginPage.vue')),
        meta: { title: 'routes.signIn', guestOnly: true, layout: 'auth' },
    },
    {
        path: '/register',
        name: 'register',
        component: themedRoute(
            'register',
            'RegisterPage',
            () => import('../pages/RegisterPage.vue'),
        ),
        meta: { title: 'routes.register', guestOnly: true, layout: 'auth' },
    },
    {
        /*
         * Reached from an e-mail, so it has to work for somebody who is not
         * signed in -- an account awaiting confirmation cannot sign in, which
         * is the whole point of the state.
         */
        path: '/confirm-account',
        name: 'confirm-account',
        component: themedRoute(
            'confirm-account',
            'ConfirmAccountPage',
            () => import('../pages/ConfirmAccountPage.vue'),
        ),
        meta: { title: 'routes.confirmAccount', layout: 'auth' },
    },
    {
        path: '/resend-confirmation',
        name: 'resend-confirmation',
        component: themedRoute(
            'resend-confirmation',
            'ResendConfirmationPage',
            () => import('../pages/ResendConfirmationPage.vue'),
        ),
        meta: { title: 'routes.resendConfirmation', layout: 'auth' },
    },
    {
        path: '/forgot-password',
        name: 'forgot-password',
        component: themedRoute(
            'forgot-password',
            'ForgotPasswordPage',
            () => import('../pages/ForgotPasswordPage.vue'),
        ),
        meta: { title: 'routes.forgotPassword', guestOnly: true, layout: 'auth' },
    },
    {
        path: '/reset-password',
        name: 'reset-password',
        component: themedRoute(
            'reset-password',
            'ResetPasswordPage',
            () => import('../pages/ResetPasswordPage.vue'),
        ),
        meta: { title: 'routes.resetPassword', guestOnly: true, layout: 'auth' },
    },
    {
        /*
         * Needs a session: the confirmation is keyed on the signed-in account
         * as well as the token, so somebody following this link while signed
         * out is sent to sign in first and returned here afterwards.
         */
        path: '/confirm-email',
        name: 'confirm-email',
        component: themedRoute(
            'confirm-email',
            'ConfirmEmailPage',
            () => import('../pages/ConfirmEmailPage.vue'),
        ),
        meta: { title: 'routes.confirmEmail', requiresAuth: true, layout: 'auth' },
    },
    {
        path: '/account/history/:tab(panel-logins|game-logins|password-changes|password-resets|email-changes)?',
        name: 'account-history',
        component: themedRoute(
            'account-history',
            'AccountHistoryPage',
            () => import('../pages/AccountHistoryPage.vue'),
        ),
        meta: { title: 'routes.accountHistory', requiresAuth: true },
    },
    {
        path: '/account/security',
        name: 'account-security',
        component: themedRoute(
            'account-security',
            'AccountSecurityPage',
            () => import('../pages/AccountSecurityPage.vue'),
        ),
        meta: { title: 'routes.security', requiresAuth: true },
    },
    {
        /*
         * Voting for the server on a listing site. Behind sign-in because a
         * vote has to be credited to somebody; the page itself refuses with a
         * 404 when no operator has configured a site, which is what keeps the
         * menu entry and the page agreeing with each other.
         */
        path: '/vote',
        name: 'vote',
        component: themedRoute('vote', 'VotePage', () => import('../pages/VotePage.vue')),
        meta: { title: 'routes.vote', requiresAuth: true },
    },
    {
        path: '/account',
        name: 'account',
        component: themedRoute('account', 'AccountPage', () => import('../pages/AccountPage.vue')),
        meta: { title: 'routes.account', requiresAuth: true },
    },
    {
        path: '/characters',
        name: 'characters',
        component: themedRoute(
            'characters',
            'CharactersPage',
            () => import('../pages/CharactersPage.vue'),
        ),
        meta: { title: 'routes.characters', requiresAuth: true },
    },
    {
        path: '/characters/:id(\\d+)',
        name: 'character',
        component: themedRoute(
            'character',
            'CharacterPage',
            () => import('../pages/CharacterPage.vue'),
        ),
        meta: { title: 'routes.character', requiresAuth: true },
    },
    {
        path: '/maps',
        name: 'maps',
        component: themedRoute('maps', 'MapsPage', () => import('../pages/MapsPage.vue')),
        meta: { title: 'routes.maps' },
    },
    {
        path: '/who-is-online',
        name: 'online',
        component: themedRoute('online', 'OnlinePage', () => import('../pages/OnlinePage.vue')),
        meta: { title: 'routes.online' },
    },
    {
        path: '/rankings/:ladder(level|zeny|alchemist|blacksmith|deaths|homunculus|guilds|mvp)',
        name: 'rankings',
        component: themedRoute(
            'rankings',
            'RankingsPage',
            () => import('../pages/RankingsPage.vue'),
        ),
        meta: { title: 'routes.rankings' },
    },
    {
        path: '/items',
        name: 'items',
        component: themedRoute('items', 'ItemsPage', () => import('../pages/ItemsPage.vue')),
        meta: { title: 'routes.items' },
    },
    {
        path: '/items/:id(\\d+)',
        name: 'item',
        component: themedRoute('item', 'ItemPage', () => import('../pages/ItemPage.vue')),
        meta: { title: 'routes.item' },
    },
    {
        path: '/monsters',
        name: 'monsters',
        component: themedRoute(
            'monsters',
            'MonstersPage',
            () => import('../pages/MonstersPage.vue'),
        ),
        meta: { title: 'routes.monsters' },
    },
    {
        path: '/monsters/:id(\\d+)',
        name: 'monster',
        component: themedRoute('monster', 'MonsterPage', () => import('../pages/MonsterPage.vue')),
        meta: { title: 'routes.monster' },
    },
    {
        /*
         * The dashboard's own sign-in.
         *
         * On the bare auth shell rather than the dashboard one, because the
         * dashboard shell carries a section list and a sign-out, neither of
         * which means anything to somebody who is not signed in yet.
         */
        path: '/admin/login',
        name: 'admin-login',
        component: themedRoute(
            'admin-login',
            'AdminLoginPage',
            () => import('../pages/admin/AdminLoginPage.vue'),
        ),
        meta: { title: 'routes.adminSignIn', layout: 'auth', adminGuestOnly: true },
    },
    {
        path: '/admin',
        name: 'admin',
        component: themedRoute(
            'admin',
            'AdminDashboardPage',
            () => import('../pages/admin/AdminDashboardPage.vue'),
        ),
        meta: { title: 'routes.admin', layout: 'admin', requiresAdmin: true },
    },
    {
        path: '/admin/downloads',
        name: 'admin-downloads',
        component: themedRoute(
            'admin-downloads',
            'AdminDownloadsPage',
            () => import('../pages/admin/AdminDownloadsPage.vue'),
        ),
        meta: { title: 'routes.adminDownloads', layout: 'admin', requiresAdmin: true },
    },
    {
        path: '/admin/news',
        name: 'admin-news',
        component: themedRoute(
            'admin-news',
            'AdminNewsPage',
            () => import('../pages/admin/AdminNewsPage.vue'),
        ),
        meta: { title: 'routes.adminNews', layout: 'admin', requiresAdmin: true },
    },
    {
        /*
         * Declared before the numeric route so `new` is not read as an id.
         * It could not be -- the id is constrained to digits -- but the
         * ordering says so without the reader having to check.
         */
        path: '/admin/news/new',
        name: 'admin-news-new',
        component: themedRoute(
            'admin-news-new',
            'AdminNewsEditPage',
            () => import('../pages/admin/AdminNewsEditPage.vue'),
        ),
        meta: { title: 'routes.adminNews', layout: 'admin', requiresAdmin: true },
    },
    {
        path: '/admin/news/:id(\\d+)',
        name: 'admin-news-edit',
        component: themedRoute(
            'admin-news-edit',
            'AdminNewsEditPage',
            () => import('../pages/admin/AdminNewsEditPage.vue'),
        ),
        meta: { title: 'routes.adminNews', layout: 'admin', requiresAdmin: true },
    },
    {
        /*
         * The wiki has two screens under it -- what the sections are called,
         * and what is in them -- so /admin/wiki is not one of them. It stands
         * for the pages, which is what somebody who typed it is nearly always
         * after, and it is also where the dashboard's own card and anything
         * bookmarked before the split still point.
         *
         * A redirect record and not a guarded one: Vue Router resolves it
         * before the guards run, so what beforeEach sees -- and what it holds
         * at Administrator -- is the pages record below.
         */
        path: '/admin/wiki',
        redirect: '/admin/wiki/pages',
    },
    {
        path: '/admin/wiki/sections',
        name: 'admin-wiki-sections',
        component: themedRoute(
            'admin-wiki-sections',
            'AdminWikiSectionsPage',
            () => import('../pages/admin/AdminWikiSectionsPage.vue'),
        ),
        meta: { title: 'routes.adminWikiSections', layout: 'admin', requiresAdmin: true },
    },
    {
        /*
         * Declared before the slug route so `new` is not read as a section's
         * address. Vue Router would rank the static segment higher anyway --
         * it is not ordering that decides this -- but the ordering says so
         * without the reader having to know that.
         */
        path: '/admin/wiki/sections/new',
        name: 'admin-wiki-section-new',
        component: themedRoute(
            'admin-wiki-section-new',
            'AdminWikiSectionEditPage',
            () => import('../pages/admin/AdminWikiSectionEditPage.vue'),
        ),
        meta: { title: 'routes.adminWikiSections', layout: 'admin', requiresAdmin: true },
    },
    {
        path: '/admin/wiki/sections/:slug([a-z0-9-]+)',
        name: 'admin-wiki-section-edit',
        component: themedRoute(
            'admin-wiki-section-edit',
            'AdminWikiSectionEditPage',
            () => import('../pages/admin/AdminWikiSectionEditPage.vue'),
        ),
        meta: { title: 'routes.adminWikiSections', layout: 'admin', requiresAdmin: true },
    },
    {
        path: '/admin/wiki/pages',
        name: 'admin-wiki',
        component: themedRoute(
            'admin-wiki',
            'AdminWikiPage',
            () => import('../pages/admin/AdminWikiPage.vue'),
        ),
        meta: { title: 'routes.adminWiki', layout: 'admin', requiresAdmin: true },
    },
    {
        path: '/admin/wiki/pages/new',
        name: 'admin-wiki-new',
        component: themedRoute(
            'admin-wiki-new',
            'AdminWikiEditPage',
            () => import('../pages/admin/AdminWikiEditPage.vue'),
        ),
        meta: { title: 'routes.adminWiki', layout: 'admin', requiresAdmin: true },
    },
    {
        /*
         * Two constrained segments, matching the public wiki route and the
         * API's own constraint, so a path this accepts is one the server can
         * answer for. `new` above cannot reach here: it is a single segment.
         */
        path: '/admin/wiki/pages/:category([a-z0-9-]+)/:slug([a-z0-9-]+)',
        name: 'admin-wiki-edit',
        component: themedRoute(
            'admin-wiki-edit',
            'AdminWikiEditPage',
            () => import('../pages/admin/AdminWikiEditPage.vue'),
        ),
        meta: { title: 'routes.adminWiki', layout: 'admin', requiresAdmin: true },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: themedRoute(
            'not-found',
            'NotFoundPage',
            () => import('../pages/NotFoundPage.vue'),
        ),
        meta: { title: 'routes.notFound', layout: 'bare' },
    },
]

export const router = createRouter({
    history: createWebHistory(),
    routes,
    // Restore the previous position on back/forward, and go to the top of
    // the page on a fresh navigation.
    scrollBehavior: (_to, _from, savedPosition) => savedPosition ?? { top: 0 },
})

/**
 * Render the not-found page without changing the address bar.
 *
 * Used where admitting that a page exists is itself the disclosure. Resolving
 * the catch-all with the path it was already on means the visitor sees exactly
 * what they would have seen had /admin never been built, rather than a
 * redirect that tells them they found something and were turned away.
 */
function asNotFound(to: RouteLocationNormalized): RouteLocationRaw {
    return {
        name: 'not-found',
        params: { pathMatch: to.path.replace(/^\//, '').split('/') },
        query: to.query,
        hash: to.hash,
    }
}

router.beforeEach(async (to) => {
    const auth = useAuthStore()

    // The first navigation has to wait for the session lookup, or an
    // authenticated visitor deep-linking to /account is bounced to sign-in.
    if (!auth.resolved) {
        await auth.refresh()
    }

    /*
     * The dashboard.
     *
     * A guest is asked to sign in, because being asked gives nothing away --
     * every site has a login page. A visitor who *is* signed in and is not an
     * administrator gets the not-found page instead of a refusal, so a curious
     * player cannot map which staff tools exist by watching which paths say
     * "forbidden".
     *
     * None of this is the protection. Each endpoint behind these pages checks
     * again on the server, which is what actually stops anything; this only
     * decides what gets drawn.
     */
    if (to.meta.requiresAdmin === true) {
        if (!auth.isAuthenticated) {
            return { name: 'admin-login', query: { redirect: to.fullPath } }
        }

        if (!auth.isAdministrator) {
            return asNotFound(to)
        }
    }

    /*
     * Signed in already: an administrator is sent on to the dashboard, and
     * anyone else is told there is no such page -- the same answer they get
     * for the dashboard itself, so the two cannot be told apart.
     */
    if (to.meta.adminGuestOnly === true && auth.isAuthenticated) {
        return auth.isAdministrator ? { name: 'admin' } : asNotFound(to)
    }

    if (to.meta.requiresAuth === true && !auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } }
    }

    if (to.meta.guestOnly === true && auth.isAuthenticated) {
        return { name: 'account' }
    }

    return true
})

router.afterEach((to) => {
    /*
     * `meta.title` holds a translation key rather than a sentence, so that the
     * tab reads in the visitor's language. An unknown key renders as itself,
     * which is visible in a tab title and therefore gets noticed.
     */
    const title = typeof to.meta.title === 'string' ? translate(to.meta.title) : null
    const site = bootstrap().game.name

    document.title = title ? `${title} — ${site}` : site
})
