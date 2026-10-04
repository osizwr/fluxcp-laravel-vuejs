<script setup lang="ts">
/**
 * A thin gold rule with a four-pointed star at its centre.
 *
 * Used between a section's title and its contents, and above the footer's
 * fine print. The star gives the eye somewhere to stop, which a plain rule
 * does not.
 */
withDefaults(defineProps<{ size?: number }>(), { size: 20 })
</script>

<template>
    <div class="yata-ornament" aria-hidden="true">
        <span class="yata-ornament__line" />
        <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" focusable="false">
            <path
                d="M12 2 L14 10 L22 12 L14 14 L12 22 L10 14 L2 12 L10 10 Z"
                fill="currentColor"
                fill-opacity="0.7"
            />
        </svg>
        <span class="yata-ornament__line" />
    </div>
</template>
