<script setup lang="ts">
import { computed, onMounted, type Component } from 'vue'
import { useRoute } from 'vue-router'
import { useServerStatusFeed } from './composables/useServerStatusFeed'
import { themedLayout } from './theme/resolve'

/**
 * The application root.
 *
 * Picks the layout a route asks for and resolves it through the active theme,
 * so a theme can restructure the shell without the router or any page knowing.
 *
 * Server status is started once here and kept current for the whole session,
 * so every page reads it from the store instead of fetching it again.
 */
const route = useRoute()
const { start } = useServerStatusFeed()

/*
 * Resolved once at module scope rather than inside the computed: each call to
 * themedLayout() builds a fresh async component, and doing that on every
 * render would discard the loaded module each time.
 */
const layouts: Record<string, Component> = {
    AppLayout: themedLayout('AppLayout', () => import('./layouts/AppLayout.vue')),
    AuthLayout: themedLayout('AuthLayout', () => import('./layouts/AuthLayout.vue')),
}

const layout = computed<Component>(() => {
    const requested = typeof route.meta.layout === 'string' ? route.meta.layout : 'AppLayout'

    return layouts[requested] ?? layouts.AppLayout
})

onMounted(start)
</script>

<template>
    <component :is="layout">
        <RouterView v-slot="{ Component: page }">
            <component :is="page" />
        </RouterView>
    </component>
</template>
