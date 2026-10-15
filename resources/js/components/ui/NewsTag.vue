<script setup lang="ts">
import { computed } from 'vue'
import { newsTag, type NewsTagTone } from '../../blocks/tags'

/**
 * The tag beside a headline, as a pill.
 *
 * Renders nothing at all for an article nobody tagged, so a caller can place
 * it unconditionally without guarding every use.
 *
 * The colour is never the only thing saying what this is: the word is in the
 * pill, and the mark beside it is hidden from assistive technology because it
 * repeats the word rather than adding to it.
 */
const props = defineProps<{ tag: string | null | undefined }>()

const view = computed(() => newsTag(props.tag))

const TONE: Record<NewsTagTone, string> = {
    neutral: 'bg-[var(--tag-neutral-bg)] text-[var(--tag-neutral-ink)]',
    green: 'bg-[var(--tag-green-bg)] text-[var(--tag-green-ink)]',
    blue: 'bg-[var(--tag-blue-bg)] text-[var(--tag-blue-ink)]',
    violet: 'bg-[var(--tag-violet-bg)] text-[var(--tag-violet-ink)]',
    amber: 'bg-[var(--tag-amber-bg)] text-[var(--tag-amber-ink)]',
    red: 'bg-[var(--tag-red-bg)] text-[var(--tag-red-ink)]',
}
</script>

<template>
    <span
        v-if="view"
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-bold tracking-[0.08em] uppercase"
        :class="TONE[view.tone]"
    >
        <span v-if="view.icon" aria-hidden="true">{{ view.icon }}</span>
        {{ view.label }}
    </span>
</template>
