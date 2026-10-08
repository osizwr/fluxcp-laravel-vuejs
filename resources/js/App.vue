<script setup lang="ts">
import { computed, onMounted, type Component } from 'vue'
import { useRoute } from 'vue-router'
import { useAnimatedCursor } from './composables/useAnimatedCursor'
import { useServerStatusFeed } from './composables/useServerStatusFeed'
import { layoutForRole, layoutRoleFor } from './theme/resolve'

/**
 * The application root.
 *
 * Picks a layout *role* for the current route and resolves it through the
 * active theme. The role comes from the theme's page composition when it
 * declares one, otherwise from the route itself, so a theme can decide that
 * its front page is a full-bleed public page while another theme keeps it
 * inside the application shell.
 *
 * Server status is started once here and kept current for the session, so
 * every block reads it from the store rather than fetching it again.
 *
 * The cursor starts here for the same reason: it belongs to the window rather
 * than to any one page, and starting it per route would restart the spin on
 * every navigation.
 */
const route = useRoute()
const { start: startServerStatus } = useServerStatusFeed()
const { start: startCursor } = useAnimatedCursor()

const layout = computed<Component>(() => {
    const pageKey = String(route.name ?? '')
    const declared = typeof route.meta.layout === 'string' ? route.meta.layout : undefined

    return layoutForRole(layoutRoleFor(pageKey, declared))
})

onMounted(() => {
    void startServerStatus()
    startCursor()
})
</script>

<template>
    <component :is="layout">
        <RouterView v-slot="{ Component: page }">
            <component :is="page" />
        </RouterView>
    </component>
</template>
