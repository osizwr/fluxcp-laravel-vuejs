<script setup lang="ts">
import { useAnnouncement } from '@/blocks/data'

/**
 * Fantasy — the announcement bar.
 *
 * A proclamation nailed to the gate. Renders nothing when there is nothing to
 * announce, so the gate is not decorated with an empty board.
 *
 * The tone comes from configuration: an event reads as gold, maintenance as
 * rust, so a player can tell good news from bad before reading it.
 */
const { announcement, dismiss } = useAnnouncement()

const toneClass = {
    info: 'bg-[var(--surface-sunken)] text-[var(--text-secondary)]',
    event: 'bg-[var(--status-warn-bg)] text-[var(--color-warn)]',
    maintenance: 'bg-[var(--status-down-bg)] text-[var(--color-down)]',
}
</script>

<template>
    <div
        v-if="announcement"
        class="relative border-b border-[var(--border-subtle)]"
        :class="toneClass[announcement.tone]"
        :role="announcement.tone === 'maintenance' ? 'alert' : 'status'"
    >
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-2">
            <p
                class="flex-1 text-center text-[0.72rem] font-semibold tracking-[0.1em] uppercase sm:text-left"
            >
                {{ announcement.message }}
                <a
                    v-if="announcement.url"
                    :href="announcement.url"
                    rel="noreferrer noopener"
                    class="ml-2 underline decoration-dotted underline-offset-2"
                >
                    {{ announcement.label ?? 'Read more' }}
                </a>
            </p>

            <button
                v-if="announcement.dismissible"
                type="button"
                class="shrink-0 rounded p-1 transition-colors hover:bg-black/20"
                aria-label="Dismiss announcement"
                @click="dismiss"
            >
                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                    />
                </svg>
            </button>
        </div>
    </div>
</template>
