<script setup lang="ts">
import { useAnnouncement } from './data'

/**
 * The operator's announcement, above everything else.
 *
 * Renders nothing when there is nothing to announce -- an empty bar is a
 * placeholder, and a placeholder takes up space while saying nothing.
 *
 * Dismissal is per announcement, keyed on a hash of the message, so editing
 * the text brings the bar back rather than staying hidden forever.
 */
const { announcement, dismiss } = useAnnouncement()

const toneClass = {
    info: 'bg-[var(--surface-sunken)] text-[var(--text-secondary)]',
    event: 'bg-[var(--status-warn-bg)] text-[var(--text-primary)]',
    maintenance: 'bg-[var(--status-down-bg)] text-[var(--text-primary)]',
}
</script>

<template>
    <div
        v-if="announcement"
        class="border-b border-[var(--border-subtle)]"
        :class="toneClass[announcement.tone]"
        :role="announcement.tone === 'maintenance' ? 'alert' : 'status'"
    >
        <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-2 text-sm">
            <p class="flex-1">
                {{ announcement.message }}
                <a
                    v-if="announcement.url"
                    :href="announcement.url"
                    class="ml-1 font-medium underline"
                    rel="noreferrer noopener"
                >
                    {{ announcement.label ?? 'Read more' }}
                </a>
            </p>

            <button
                v-if="announcement.dismissible"
                type="button"
                class="shrink-0 rounded p-1 hover:bg-black/10"
                aria-label="Dismiss announcement"
                @click="dismiss"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                    />
                </svg>
            </button>
        </div>
    </div>
</template>
