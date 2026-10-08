<script setup lang="ts">
import { resolveBlock } from '../theme/blocks'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * The shell for the application's utility pages.
 *
 * Differs from PublicLayout only in that content is constrained to a column:
 * an account page or a character table wants a readable measure, where a
 * landing page wants full-bleed sections.
 *
 * The chrome is composed from blocks rather than written here, so a theme
 * restyles the masthead once and both layouts follow. Before this, each layout
 * carried its own copy of the masthead and the two drifted apart.
 */
const AnnouncementBar = resolveBlock('announcement-bar')
const Navbar = resolveBlock('navbar')
const Footer = resolveBlock('footer')
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-[var(--surface-raised)] focus:px-3 focus:py-2 focus:text-sm"
        >
            {{ t('common.skipToContent') }}
        </a>

        <component :is="AnnouncementBar" v-if="AnnouncementBar" />
        <component :is="Navbar" v-if="Navbar" />

        <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
            <slot />
        </main>

        <component :is="Footer" v-if="Footer" />
    </div>
</template>
