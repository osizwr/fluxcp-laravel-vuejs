<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { composedBlocks } from '../theme/blocks'

/**
 * Renders a page the active theme composed in theme.json.
 *
 * The entire implementation is a loop. That is the design: page structure is a
 * list of block names the theme owns, and this file only walks it. Nothing
 * here knows what any block is or what data it needs, so adding a block to a
 * page never touches this component.
 *
 * Routes whose theme declares no composition never reach here -- the router
 * sends them to the theme's page override or the core page instead.
 */
const route = useRoute()

const blocks = computed(() => composedBlocks(String(route.name ?? '')))
</script>

<template>
    <!--
        Keyed by name and position, because a composition may legitimately use
        the same block twice -- two ranking ladders, say -- and the name alone
        would not be unique.
    -->
    <component
        :is="entry.component"
        v-for="(entry, index) in blocks"
        :key="`${entry.name}-${index}`"
        v-bind="entry.props"
    />
</template>
