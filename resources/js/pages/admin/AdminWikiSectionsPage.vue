<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AlertMessage from '../../components/ui/AlertMessage.vue'
import AppButton from '../../components/ui/AppButton.vue'
import WikiIcon from '../../components/ui/WikiIcon.vue'
import { api, ApiError } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * The guide's sections.
 *
 * The other half of the wiki screen: this one is what the sections are called,
 * the page list is what is in them. Split because they are edited at different
 * times by different people -- the shape of a guide is settled once and then
 * left alone, while its pages are written all year -- and because a section had
 * nowhere to be edited at all before this.
 *
 * Every section the wiki has, not only the ones somebody has described here,
 * plus one kind the wiki itself does not show: a section named here that has no
 * page in it yet.
 */
const { t } = useTranslation()

type Source = 'file' | 'database' | 'both'

interface Section {
    slug: string
    title: string
    description: string | null
    icon: string | null
    sort_order: number | null
    pages: number
    source: Source
}

const sections = ref<Section[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const payload = await api.get<{ data: { sections: Section[] } }>('admin/wiki/sections')

        sections.value = payload.data.sections
    } catch {
        error.value = t('admin.wiki.sections.loadFailed')
    } finally {
        loading.value = false
    }
}

/**
 * Drop what the dashboard says about a section.
 *
 * The one action this screen has, and it means two things depending on what is
 * underneath -- a directory gets its `_category.md` back, a section written
 * here forgets the name it was given. Both are the same sentence from the
 * operator's side, so they are the same button with the wording that fits.
 *
 * Removing the pages is deliberately not offered here. A section is its pages;
 * the wiki drops any section left empty, so taking one off the wiki is done on
 * the page screen, one reversible removal at a time.
 */
async function revert(section: Section): Promise<void> {
    const confirmation =
        section.source === 'both'
            ? t('admin.wiki.sections.revertConfirm', { title: section.title })
            : t('admin.wiki.sections.forgetConfirm', { title: section.title })

    if (!window.confirm(confirmation)) {
        return
    }

    error.value = null
    notice.value = null

    try {
        await api.delete(`admin/wiki/sections/${section.slug}`)

        // Reported the way it was asked for: the button said one of two
        // things, and so should the line that confirms it happened.
        notice.value =
            section.source === 'both'
                ? t('admin.wiki.sections.reverted')
                : t('admin.wiki.sections.forgotten')
        await load()
    } catch (failure) {
        error.value = failure instanceof ApiError ? failure.message : t('common.genericError')
    }
}

onMounted(load)
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('admin.wiki.sections.title') }}
                </h1>
                <p class="mt-0.5 text-sm text-[var(--text-secondary)]">
                    {{ t('admin.wiki.sections.summary') }}
                </p>
            </div>

            <AppButton variant="primary" to="/admin/wiki/sections/new">
                {{ t('admin.wiki.sections.add') }}
            </AppButton>
        </div>

        <!--
            The same trade the page screen states, said about sections: the
            `_category.md` on disk is never written to, and describing a
            section here is a layer over it rather than a change to it.
        -->
        <AlertMessage tone="info" class="mt-4">
            {{ t('admin.wiki.sections.overlayNote') }}
        </AlertMessage>

        <AlertMessage v-if="error" tone="error" class="mt-4">{{ error }}</AlertMessage>
        <AlertMessage v-if="notice" tone="success" class="mt-4">{{ notice }}</AlertMessage>

        <p v-if="loading" class="mt-6 text-sm text-[var(--text-secondary)]">
            {{ t('common.loading') }}
        </p>

        <template v-else>
            <p v-if="sections.length === 0" class="panel mt-4 p-4 text-sm text-[var(--text-muted)]">
                {{ t('admin.wiki.sections.empty') }}
            </p>

            <ul v-else class="panel mt-4 divide-y divide-[var(--border-subtle)] p-4">
                <li
                    v-for="section in sections"
                    :key="section.slug"
                    class="flex flex-wrap items-center gap-2 py-3 first:pt-0 last:pb-0"
                >
                    <!--
                        The name and the icon are one block, and it takes the
                        whole row until there is width for the rest beside it.
                        Left to flex-1 alone a description gets squeezed into a
                        column one word wide on a phone, which is where an
                        operator fixing a typo on the way somewhere actually
                        reads this screen.
                    -->
                    <div class="flex min-w-0 flex-1 basis-full items-start gap-2 sm:basis-0">
                        <WikiIcon
                            :name="section.icon"
                            class="mt-0.5 size-4 shrink-0 text-[var(--text-muted)]"
                        />

                        <div class="min-w-0">
                            <RouterLink
                                :to="`/admin/wiki/sections/${section.slug}`"
                                class="font-medium underline-offset-2 hover:underline"
                            >
                                {{ section.title }}
                            </RouterLink>
                            <p class="text-[0.8125rem] text-[var(--text-muted)]">
                                /wiki/{{ section.slug }}
                            </p>
                            <p
                                v-if="section.description"
                                class="mt-0.5 text-[0.8125rem] text-[var(--text-secondary)]"
                            >
                                {{ section.description }}
                            </p>
                        </div>
                    </div>

                    <!--
                        A section with no pages is a row and nothing more. Said
                        here rather than left to be discovered, because the
                        wiki drops empty sections and an operator who had just
                        created one would otherwise go looking for it.
                    -->
                    <RouterLink
                        v-if="section.pages > 0"
                        :to="`/admin/wiki/pages?section=${section.slug}`"
                        class="text-[0.8125rem] text-[var(--text-secondary)] underline underline-offset-2 hover:text-[var(--text-primary)]"
                    >
                        {{ t('admin.wiki.sections.pageCount', { count: section.pages }) }}
                    </RouterLink>

                    <span v-else class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('admin.wiki.sections.unpublished') }}
                    </span>

                    <span class="admin-nav-soon">
                        {{ t(`admin.wiki.sections.source.${section.source}`) }}
                    </span>

                    <AppButton
                        variant="secondary"
                        size="sm"
                        :to="`/admin/wiki/sections/${section.slug}`"
                    >
                        {{ t('admin.edit') }}
                    </AppButton>

                    <AppButton
                        v-if="section.source !== 'file'"
                        variant="secondary"
                        size="sm"
                        @click="revert(section)"
                    >
                        {{ section.source === 'both' ? t('admin.wiki.revert') : t('admin.remove') }}
                    </AppButton>
                </li>
            </ul>
        </template>
    </div>
</template>
