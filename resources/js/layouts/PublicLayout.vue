<script setup lang="ts">
import { resolveBlock } from '../theme/blocks'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * The shell for public-facing pages.
 *
 * Distinct from AppLayout: a landing page wants full-bleed sections and an
 * announcement bar, where a utility page wants a constrained column. Which one
 * a page uses is the theme's decision, declared per page in theme.json.
 *
 * The announcement bar, navbar and footer are *blocks*, not markup written
 * here, so a theme replaces them the same way it replaces any other block.
 * They live in the layout rather than in every page's block list because
 * repeating three entries across every page would be noise, not control.
 *
 * Content is not wrapped in a container: a composed page's blocks manage their
 * own width, which is what lets one be full-bleed and the next inset.
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

        <main id="main" class="flex-1">
            <slot />
        </main>

        <component :is="Footer" v-if="Footer" />
    </div>
</template>
