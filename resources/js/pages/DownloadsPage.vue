<script setup lang="ts">
import StateBlock from '../components/ui/StateBlock.vue'
import { useDownloadsData } from '../blocks/data'
import { resolveBlock } from '../theme/blocks'
import { useGame } from '../composables/useGame'
import { useTranslation } from '../i18n'

const { t } = useTranslation()
const { game } = useGame()

/**
 * Where to get the client.
 *
 * Three blocks and a heading. The sections are blocks rather than markup
 * written here for the same reason the masthead is: a theme that wants the
 * packages drawn differently overrides one file, and a theme that wants a
 * different page altogether composes `downloads` in theme.json without this
 * page being involved at all.
 *
 * Resolved through the registry rather than imported directly, so a theme's
 * override applies here too -- importing them would quietly pin the page to
 * the core versions.
 */
const downloads = useDownloadsData()

const DownloadClients = resolveBlock('download-clients')
const SystemRequirements = resolveBlock('system-requirements')
const InstallGuide = resolveBlock('install-guide')
</script>

<template>
    <div>
        <!--
            The masthead and the packages are one card, not two.

            Which means the card is drawn here rather than by either of them:
            a block cannot see what it was placed next to, so it cannot decide
            to share a panel with it. `framed: false` tells the packages block
            that the container and the panel have already been drawn and it
            should render into them.

            The geometry is the wiki's -- `max-w-6xl` and `p-5 sm:p-8` -- so
            the two pages line up at every width instead of being nearly the
            same by coincidence.
        -->
        <section class="mx-auto max-w-6xl px-4 pt-8 sm:pt-10">
            <div class="panel p-5 sm:p-8">
                <header>
                    <p
                        class="text-[0.75rem] font-semibold tracking-[0.14em] uppercase text-[var(--text-muted)]"
                    >
                        {{ game.name }}
                    </p>

                    <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                        {{ t('downloads.title') }}
                    </h1>

                    <!-- <p class="mt-1.5 max-w-2xl text-sm text-[var(--text-secondary)]">
                        {{ t('downloads.description') }}
                    </p> -->
                </header>

                <!--
                    No class passed in to space it: the block has no root to
                    put one on when this server has published no packages,
                    and an attribute that lands nowhere is a console warning
                    in development and silence in production. It spaces
                    itself, and renders nothing at all when there is nothing
                    to show.
                -->
                <component :is="DownloadClients" v-if="DownloadClients" :framed="false" />
            </div>
        </section>

        <!--
            Nothing configured at all. Said plainly rather than rendered as
            three empty headings: an operator who has not filled this in yet is
            better served by being told so, and a visitor is better served by
            an answer than by a page that looks broken.
        -->
        <StateBlock
            v-if="downloads.state.empty"
            variant="empty"
            :title="t('downloads.empty')"
            :description="t('downloads.emptyBody')"
        />

        <!--
            The packages are not here: they are inside the masthead's card
            above, which is the whole point of that card. These two draw their
            own, because what is inside them is white panels -- a tab strip,
            a numbered list -- and a white panel on a white card washes out.
        -->
        <template v-else>
            <component :is="SystemRequirements" v-if="SystemRequirements" />
            <component :is="InstallGuide" v-if="InstallGuide" />
        </template>
    </div>
</template>
