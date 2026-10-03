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
        path: '/who-is-online',
        name: 'online',
        component: themedRoute('online', 'OnlinePage', () => import('../pages/OnlinePage.vue')),
        meta: { title: "Who's online" },
    },
    {
        path: '/rankings/:ladder(level|zeny)',
        name: 'rankings',
        component: themedRoute('rankings', 'RankingsPage', () => import('../pages/RankingsPage.vue')),
        meta: { title: 'Rankings' },
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
