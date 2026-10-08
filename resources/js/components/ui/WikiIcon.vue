<script setup lang="ts">
import { computed } from 'vue'

/**
 * The little mark beside a wiki section's name.
 *
 * A fixed set rather than an image path, for two reasons. A section's icon is
 * written in a Markdown file by whoever maintains the guide, and a filename
 * there would be a way to point the page at an arbitrary URL; and a drawn
 * icon inherits the text colour, so it follows a theme without anybody having
 * to export a second copy of it in white.
 *
 * An unknown or missing name falls back to the book, which is the one icon
 * that is never wrong on a wiki.
 */
const props = defineProps<{ name?: string | null }>()

/*
 * Stroked paths on a 24x24 grid, in the vocabulary a guide actually needs:
 * where to start, what the systems are, what things cost, when things happen.
 * Kept small on purpose -- a set nobody can remember is a set nobody picks
 * the right icon from.
 */
const ICONS: Record<string, string[]> = {
    book: [
        'M4 19.5A2.5 2.5 0 0 1 6.5 17H20',
        'M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z',
    ],
    play: ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z', 'm10 8 6 4-6 4V8z'],
    globe: [
        'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z',
        'M2 12h20',
        'M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z',
    ],
    help: [
        'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z',
        'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3',
        'M12 17h.01',
    ],
    sword: ['M14.5 17.5 3 6V3h3l11.5 11.5', 'M13 19l6-6', 'M16 16l4 4', 'M19 21l2-2'],
    shield: ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
    coins: [
        'M8 14a6 6 0 1 0 0-12 6 6 0 0 0 0 12z',
        'M18.1 10.4A6 6 0 1 1 10.3 18',
        'M7 6h1v4',
        'm16.7 13.9.7.7-2.8 2.8',
    ],
    key: [
        'M7.5 21a5.5 5.5 0 1 0 0-11 5.5 5.5 0 0 0 0 11z',
        'm21 2-9.6 9.6',
        'm15.5 7.5 3 3L22 7l-3-3',
    ],
    calendar: [
        'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
        'M16 2v4',
        'M8 2v4',
        'M3 10h18',
    ],
    flame: [
        'M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.4-.5-2-1-3-1.1-2.1-.2-4 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.2.4-2.3 1-3a2.5 2.5 0 0 0 2.5 2.5z',
    ],
    map: ['M1 6v16l7-4 8 4 7-4V2l-7 4-8-4-7 4z', 'M8 2v16', 'M16 6v16'],
    users: [
        'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2',
        'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'M23 21v-2a4 4 0 0 0-3-3.9',
        'M16 3.1a4 4 0 0 1 0 7.8',
    ],
}

const paths = computed(() => ICONS[props.name ?? ''] ?? ICONS.book)
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        <path v-for="(d, index) in paths" :key="index" :d="d" />
    </svg>
</template>
