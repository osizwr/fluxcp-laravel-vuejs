<script setup lang="ts">
import { useAnnouncement } from '@/blocks/data'

/**
 * The thin bar above everything.
 *
 * A band of dark gold, around thirty pixels tall, in small tracked capitals.
 * Its job is to be noticed once and then ignored, so it carries no fill
 * bright enough to compete with the hero beneath it.
 *
 * Renders nothing when there is nothing to announce. Dismissal, tone and the
 * message itself all come from the composable, which is fed by the operator's
 * configuration — this block decides only how it looks.
 */
const { announcement, dismiss } = useAnnouncement()

/*
 * Three tones, three temperatures. Maintenance is the only one that gets any
 * real colour, because it is the only one that means "something is wrong".
 */
const toneClass = {
    info: 'yata-bar--info',
    event: 'yata-bar--event',
    maintenance: 'yata-bar--maintenance',
}
</script>

<template>
    <div
        v-if="announcement"
        class="yata-bar"
        :class="toneClass[announcement.tone]"
        :role="announcement.tone === 'maintenance' ? 'alert' : 'status'"
    >
        <div class="mx-auto flex w-full max-w-7xl items-center gap-4 px-4">
            <p class="yata-bar__text">
                {{ announcement.message }}
            </p>

            <a
                v-if="announcement.url"
                :href="announcement.url"
                class="yata-bar__link shrink-0"
                rel="noreferrer noopener"
            >
                {{ announcement.label ?? 'Learn more' }}
                <span aria-hidden="true">&rarr;</span>
            </a>

            <button
                v-if="announcement.dismissible"
                type="button"
                class="yata-bar__close shrink-0"
                @click="dismiss"
            >
                <span class="sr-only">Dismiss this announcement</span>
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    </div>
</template>

<style>
/*
 * Scoped by slug rather than by Vue's `scoped`, for consistency with the rest
 * of this theme's styling and so the rules are readable next to the markup
 * they belong to.
 */
:root[data-theme-slug='yatagarasu'] .yata-bar {
    display: flex;
    align-items: center;
    min-height: 32px;
    border-bottom: 1px solid var(--yata-rule);
}

:root[data-theme-slug='yatagarasu'] .yata-bar--info {
    background-color: #130f08;
}

:root[data-theme-slug='yatagarasu'] .yata-bar--event {
    background-color: #1a1308;
}

:root[data-theme-slug='yatagarasu'] .yata-bar--maintenance {
    background-color: var(--status-down-bg);
    border-bottom-color: color-mix(in oklab, var(--color-down) 45%, transparent);
}

/*
 * One line with an ellipsis where there is room for one, and wrapping where
 * there is not.
 *
 * `min-width: 0` is the part that matters: a flex item will not shrink below
 * its own min-content width without it, and `white-space: nowrap` makes that
 * min-content width the whole sentence. Without it the bar pushed the page
 * 230px wider than the phone it was on.
 */
:root[data-theme-slug='yatagarasu'] .yata-bar__text {
    flex: 1 1 0;
    min-width: 0;
    padding-block: 0.4rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: color-mix(in oklab, var(--yata-ivory) 76%, transparent);
}

@media (min-width: 640px) {
    :root[data-theme-slug='yatagarasu'] .yata-bar__text {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        padding-block: 0;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-bar__link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--color-accent-400);
    text-decoration: none;
    border-bottom: 1px solid transparent;
    transition: color 160ms var(--yata-ease), border-color 160ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-bar__link:hover {
    color: var(--color-accent-300);
    border-bottom-color: currentColor;
}

:root[data-theme-slug='yatagarasu'] .yata-bar__close {
    padding: 0 0.25rem;
    font-size: 1rem;
    line-height: 1;
    color: var(--text-muted);
    background: none;
    border: 0;
    cursor: pointer;
    transition: color 160ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-bar__close:hover {
    color: var(--yata-ivory);
}
</style>
