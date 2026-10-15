<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AlertMessage from '../../components/ui/AlertMessage.vue'
import AppButton from '../../components/ui/AppButton.vue'
import { api, ApiError } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * The guide's pages, listed by section.
 *
 * Every page the wiki has, not only the ones somebody has edited here: the
 * listing is built from the library's own index, so a page that is still a
 * file in version control appears alongside one written in the browser, and
 * the badge says which is which.
 *
 * What the sections are called is the other screen. This one only groups by
 * them, and `?section=` narrows it to one -- which is how the section list
 * links through to the pages of a section.
 */
const { t } = useTranslation()

const route = useRoute()

type Source = 'file' | 'database' | 'both'

interface Page {
    path: string
    title: string
    summary: string | null
    updated_at: string | null
    source: Source
}

interface Section {
    slug: string
    title: string
    pages: Page[]
}

interface Hidden {
    path: string
    title: string
    category: string
}

const sections = ref<Section[]>([])
const hidden = ref<Hidden[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

/** The section being looked at on its own, or null for the whole guide. */
const only = computed(() => (typeof route.query.section === 'string' ? route.query.section : null))

const visible = computed(() =>
    only.value === null
        ? sections.value
        : sections.value.filter((section) => section.slug === only.value),
)

/*
 * A new page starts in whichever section is being looked at, so that writing
 * the third page of a section is not three fields of typing the section's name
 * again. Carried in the query because the editor's own address is the page's,
 * and a page being created does not have one yet.
 */
const writeTo = computed(() =>
    only.value === null ? '/admin/wiki/pages/new' : `/admin/wiki/pages/new?section=${only.value}`,
)

/** Removed pages, narrowed the same way, so the filter means one thing. */
const visibleHidden = computed(() =>
    only.value === null
        ? hidden.value
        : hidden.value.filter((page) => page.category === only.value),
)

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const payload = await api.get<{ data: { sections: Section[]; hidden: Hidden[] } }>(
            'admin/wiki',
        )

        sections.value = payload.data.sections
        hidden.value = payload.data.hidden
    } catch {
        error.value = t('admin.wiki.loadFailed')
    } finally {
        loading.value = false
    }
}

async function remove(page: Page): Promise<void> {
    if (!window.confirm(t('admin.wiki.removeConfirm', { title: page.title }))) {
        return
    }

    await act(() => api.delete(`admin/wiki/${page.path}`), t('admin.wiki.removed'))
}

/**
 * Put a page back on its file, or delete one that never had a file.
 *
 * Offered for anything with a row behind it -- edited, or hidden -- because
 * in both cases the row is the thing standing between the reader and whatever
 * is on disk.
 */
async function revert(path: string): Promise<void> {
    if (!window.confirm(t('admin.wiki.revertConfirm'))) {
        return
    }

    await act(() => api.post(`admin/wiki/${path}/revert`), t('admin.wiki.reverted'))
}

async function act(request: () => Promise<unknown>, success: string): Promise<void> {
    error.value = null
    notice.value = null

    try {
        await request()
        notice.value = success
        await load()
    } catch (failure) {
        error.value = failure instanceof ApiError ? failure.message : t('common.genericError')
    }
}

function badge(source: Source): string {
    return t(`admin.wiki.source.${source}`)
}

onMounted(load)
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('admin.wiki.pages.title') }}
                </h1>
                <p class="mt-0.5 text-sm text-[var(--text-secondary)]">
                    {{ t('admin.wiki.pages.summary') }}
                </p>
            </div>

            <AppButton variant="primary" :to="writeTo">
                {{ t('admin.wiki.write') }}
            </AppButton>
        </div>

        <!--
            Said plainly when the list is not the whole guide, with the way
            back: a filtered list that looked like the complete one would read
            as a wiki that had lost most of its pages.
        -->
        <p v-if="only" class="mt-3 text-sm text-[var(--text-secondary)]">
            {{ t('admin.wiki.pages.filtered', { section: only }) }}
            <RouterLink
                to="/admin/wiki/pages"
                class="underline underline-offset-2 hover:text-[var(--text-primary)]"
            >
                {{ t('admin.wiki.pages.showAll') }}
            </RouterLink>
        </p>

        <!--
            The trade this screen makes, said once and plainly rather than
            discovered later: a page edited here stops tracking its file, and
            a deploy will not update it.
        -->
        <AlertMessage tone="info" class="mt-4">{{ t('admin.wiki.overlayNote') }}</AlertMessage>

        <AlertMessage v-if="error" tone="error" class="mt-4">{{ error }}</AlertMessage>
        <AlertMessage v-if="notice" tone="success" class="mt-4">{{ notice }}</AlertMessage>

        <p v-if="loading" class="mt-6 text-sm text-[var(--text-secondary)]">
            {{ t('common.loading') }}
        </p>

        <template v-else>
            <p v-if="visible.length === 0" class="panel mt-4 p-4 text-sm text-[var(--text-muted)]">
                {{ only ? t('admin.wiki.pages.noneInSection') : t('admin.wiki.pages.none') }}
            </p>

            <section v-for="section in visible" :key="section.slug" class="panel mt-4 p-4">
                <h2 class="text-sm font-medium">
                    {{ section.title }}
                    <span class="font-normal text-[var(--text-muted)]">/{{ section.slug }}</span>
                </h2>

                <ul class="mt-2 divide-y divide-[var(--border-subtle)]">
                    <li
                        v-for="page in section.pages"
                        :key="page.path"
                        class="flex flex-wrap items-center gap-2 py-2"
                    >
                        <div class="min-w-0 flex-1">
                            <RouterLink
                                :to="`/admin/wiki/pages/${page.path}`"
                                class="font-medium underline-offset-2 hover:underline"
                            >
                                {{ page.title }}
                            </RouterLink>
                            <p class="text-[0.8125rem] text-[var(--text-muted)]">
                                /wiki/{{ page.path }}
                            </p>
                        </div>

                        <span class="admin-nav-soon">{{ badge(page.source) }}</span>

                        <AppButton
                            variant="secondary"
                            size="sm"
                            :to="`/admin/wiki/pages/${page.path}`"
                        >
                            {{ t('admin.edit') }}
                        </AppButton>

                        <AppButton
                            v-if="page.source !== 'file'"
                            variant="secondary"
                            size="sm"
                            @click="revert(page.path)"
                        >
                            {{ t('admin.wiki.revert') }}
                        </AppButton>

                        <AppButton variant="danger" size="sm" @click="remove(page)">
                            {{ t('admin.remove') }}
                        </AppButton>
                    </li>
                </ul>
            </section>

            <!--
                Removed pages, listed so they can be brought back. Without this
                a hidden page would be gone from the dashboard as well as from
                the wiki, and the only way back would be the database.
            -->
            <section v-if="visibleHidden.length > 0" class="panel mt-4 p-4">
                <h2 class="text-sm font-medium">{{ t('admin.wiki.removedHeading') }}</h2>
                <p class="text-[0.8125rem] text-[var(--text-muted)]">
                    {{ t('admin.wiki.removedHelp') }}
                </p>

                <ul class="mt-2 divide-y divide-[var(--border-subtle)]">
                    <li
                        v-for="page in visibleHidden"
                        :key="page.path"
                        class="flex flex-wrap items-center gap-2 py-2"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ page.title }}</p>
                            <p class="text-[0.8125rem] text-[var(--text-muted)]">
                                /wiki/{{ page.path }}
                            </p>
                        </div>

                        <AppButton variant="secondary" size="sm" @click="revert(page.path)">
                            {{ t('admin.wiki.restore') }}
                        </AppButton>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
