<script setup lang="ts">
import { onMounted } from 'vue'
import DefaultLayout from './layouts/DefaultLayout.vue'
import { useServerStatusFeed } from './composables/useServerStatusFeed'

/**
 * The application root.
 *
 * Server status is loaded once here and then kept current for the whole
 * session, so every page can read it from the store without each one fetching
 * it again.
 */
const { start } = useServerStatusFeed()

onMounted(start)
</script>

<template>
    <DefaultLayout>
        <RouterView v-slot="{ Component }">
            <component :is="Component" />
        </RouterView>
    </DefaultLayout>
</template>
