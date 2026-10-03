<script setup lang="ts">
import { computed, onMounted, type Component } from 'vue'
import { useRoute } from 'vue-router'
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
 */
const route = useRoute()
const { start } = useServerStatusFeed()

const layout = computed<Component>(() => {
    const pageKey = String(route.name ?? '')
    const declared = typeof route.meta.layout === 'string' ? route.meta.layout : undefined

    return layoutForRole(layoutRoleFor(pageKey, declared))
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
