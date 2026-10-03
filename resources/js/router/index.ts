import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { bootstrap } from '../theme/bootstrap'
import { themedRoute } from '../theme/resolve'

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
        meta: { title: 'Server status', layout: 'public' },
    },
    {
        path: '/sign-in',
        name: 'login',
        component: themedRoute('login', 'LoginPage', () => import('../pages/LoginPage.vue')),
        meta: { title: 'Sign in', guestOnly: true, layout: 'auth' },
    },
    {
        path: '/register',
        name: 'register',
        component: themedRoute('register', 'RegisterPage', () => import('../pages/RegisterPage.vue')),
        meta: { title: 'Create an account', guestOnly: true, layout: 'auth' },
    },
    {
        /*
         * Reached from an e-mail, so it has to work for somebody who is not
         * signed in -- an account awaiting confirmation cannot sign in, which
         * is the whole point of the state.
         */
        path: '/confirm-account',
        name: 'confirm-account',
        component: themedRoute('confirm-account', 'ConfirmAccountPage', () =>
            import('../pages/ConfirmAccountPage.vue'),
        ),
        meta: { title: 'Confirm your account', layout: 'auth' },
    },
    {
        path: '/resend-confirmation',
        name: 'resend-confirmation',
        component: themedRoute('resend-confirmation', 'ResendConfirmationPage', () =>
            import('../pages/ResendConfirmationPage.vue'),
        ),
        meta: { title: 'Resend confirmation', layout: 'auth' },
    },
    {
        path: '/forgot-password',
        name: 'forgot-password',
        component: themedRoute('forgot-password', 'ForgotPasswordPage', () =>
            import('../pages/ForgotPasswordPage.vue'),
        ),
        meta: { title: 'Forgot your password', guestOnly: true, layout: 'auth' },
    },
    {
        path: '/reset-password',
        name: 'reset-password',
        component: themedRoute('reset-password', 'ResetPasswordPage', () =>
            import('../pages/ResetPasswordPage.vue'),
        ),
        meta: { title: 'Choose a new password', guestOnly: true, layout: 'auth' },
    },
    {
        /*
         * Needs a session: the confirmation is keyed on the signed-in account
         * as well as the token, so somebody following this link while signed
         * out is sent to sign in first and returned here afterwards.
         */
        path: '/confirm-email',
        name: 'confirm-email',
        component: themedRoute('confirm-email', 'ConfirmEmailPage', () =>
            import('../pages/ConfirmEmailPage.vue'),
        ),
        meta: { title: 'Confirm your e-mail address', requiresAuth: true, layout: 'auth' },
    },
    {
        path: '/account/history/:tab(panel-logins|game-logins|password-changes|password-resets|email-changes)?',
        name: 'account-history',
        component: themedRoute('account-history', 'AccountHistoryPage', () =>
            import('../pages/AccountHistoryPage.vue'),
        ),
        meta: { title: 'Account history', requiresAuth: true },
    },
    {
        path: '/account/security',
        name: 'account-security',
        component: themedRoute('account-security', 'AccountSecurityPage', () =>
            import('../pages/AccountSecurityPage.vue'),
        ),
        meta: { title: 'Security', requiresAuth: true },
    },
    {
        path: '/account',
        name: 'account',
        component: themedRoute('account', 'AccountPage', () => import('../pages/AccountPage.vue')),
        meta: { title: 'My account', requiresAuth: true },
    },
    {
        path: '/characters',
        name: 'characters',
        component: themedRoute('characters', 'CharactersPage', () => import('../pages/CharactersPage.vue')),
        meta: { title: 'My characters', requiresAuth: true },
    },
    {
        path: '/characters/:id(\\d+)',
        name: 'character',
        component: themedRoute('character', 'CharacterPage', () =>
            import('../pages/CharacterPage.vue'),
        ),
        meta: { title: 'Character', requiresAuth: true },
    },
    {
        path: '/maps',
        name: 'maps',
        component: themedRoute('maps', 'MapsPage', () => import('../pages/MapsPage.vue')),
        meta: { title: 'Map activity' },
    },
    {
        path: '/who-is-online',
        name: 'online',
        component: themedRoute('online', 'OnlinePage', () => import('../pages/OnlinePage.vue')),
        meta: { title: "Who's online" },
    },
    {
        path: '/rankings/:ladder(level|zeny|alchemist|blacksmith|deaths|homunculus|guilds|mvp)',
        name: 'rankings',
        component: themedRoute('rankings', 'RankingsPage', () => import('../pages/RankingsPage.vue')),
        meta: { title: 'Rankings' },
    },
    {
        path: '/items',
        name: 'items',
        component: themedRoute('items', 'ItemsPage', () => import('../pages/ItemsPage.vue')),
        meta: { title: 'Items' },
    },
    {
        path: '/items/:id(\\d+)',
        name: 'item',
        component: themedRoute('item', 'ItemPage', () => import('../pages/ItemPage.vue')),
        meta: { title: 'Item' },
    },
    {
        path: '/monsters',
        name: 'monsters',
        component: themedRoute('monsters', 'MonstersPage', () => import('../pages/MonstersPage.vue')),
        meta: { title: 'Monsters' },
    },
    {
        path: '/monsters/:id(\\d+)',
        name: 'monster',
        component: themedRoute('monster', 'MonsterPage', () => import('../pages/MonsterPage.vue')),
        meta: { title: 'Monster' },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: themedRoute('not-found', 'NotFoundPage', () => import('../pages/NotFoundPage.vue')),
        meta: { title: 'Page not found' },
    },
]

export const router = createRouter({
    history: createWebHistory(),
    routes,
    // Restore the previous position on back/forward, and go to the top of
    // the page on a fresh navigation.
    scrollBehavior: (_to, _from, savedPosition) => savedPosition ?? { top: 0 },
})

router.beforeEach(async (to) => {
    const auth = useAuthStore()

    // The first navigation has to wait for the session lookup, or an
    // authenticated visitor deep-linking to /account is bounced to sign-in.
    if (!auth.resolved) {
        await auth.refresh()
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
    const title = typeof to.meta.title === 'string' ? to.meta.title : null
    const site = bootstrap().game.name

    document.title = title ? `${title} — ${site}` : site
})
