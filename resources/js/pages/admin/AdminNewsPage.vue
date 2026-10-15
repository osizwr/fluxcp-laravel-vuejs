<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AlertMessage from '../../components/ui/AlertMessage.vue'
import AppButton from '../../components/ui/AppButton.vue'
import NewsTag from '../../components/ui/NewsTag.vue'
import { api, ApiError } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * The news articles, listed.
 *
 * Reads the management endpoint rather than the public one: that is the list
 * ordered for editing rather than for reading, and it is the one already held
 * at Administrator by the permission map.
 */
const { t } = useTranslation()

interface Article {
    id: number
    title: string
    author: string
    tag: string | null
    published_at: string | null
    updated_at: string | null
}

const articles = ref<Article[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const payload = await api.get<{ data: Article[] }>('news/manage', { per_page: 100 })

        articles.value = payload.data
    } catch {
        error.value = t('admin.news.loadFailed')
    } finally {
        loading.value = false
    }
}

/**
 * Confirmed before it happens, and there is no undo: the row is removed
 * outright rather than flagged, because that is what the legacy schema
 * supports -- there is no draft or deleted column to set.
 */
async function remove(article: Article): Promise<void> {
    if (!window.confirm(t('admin.news.deleteConfirm', { title: article.title }))) {
        return
    }

    error.value = null
    notice.value = null

    try {
        await api.delete(`news/${article.id}`)
        articles.value = articles.value.filter((row) => row.id !== article.id)
        notice.value = t('admin.news.deleted')
    } catch (failure) {
        error.value = failure instanceof ApiError ? failure.message : t('common.genericError')
    }
}

/** A date somebody reads, in their own locale, or a dash when there is none. */
function when(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleDateString()
}

onMounted(load)
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-xl font-semibold tracking-tight">{{ t('admin.news.title') }}</h1>
                <p class="mt-0.5 text-sm text-[var(--text-secondary)]">
                    {{ t('admin.news.summary') }}
                </p>
            </div>

            <AppButton variant="primary" to="/admin/news/new">
                {{ t('admin.news.write') }}
            </AppButton>
        </div>

        <AlertMessage v-if="error" tone="error" class="mt-4">{{ error }}</AlertMessage>
        <AlertMessage v-if="notice" tone="success" class="mt-4">{{ notice }}</AlertMessage>

        <p v-if="loading" class="mt-6 text-sm text-[var(--text-secondary)]">
            {{ t('common.loading') }}
        </p>

        <p
            v-else-if="articles.length === 0"
            class="panel mt-5 p-6 text-center text-sm text-[var(--text-secondary)]"
        >
            {{ t('admin.news.empty') }}
        </p>

        <ul v-else class="panel mt-5 divide-y divide-[var(--border-subtle)]">
            <li
                v-for="article in articles"
                :key="article.id"
                class="flex flex-wrap items-center gap-3 p-3"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <RouterLink
                            :to="`/admin/news/${article.id}`"
                            class="font-medium underline-offset-2 hover:underline"
                        >
                            {{ article.title }}
                        </RouterLink>

                        <!-- The same pill readers see, so an editor can tell
                             at a glance which posts have been tagged and
                             which have been left plain. -->
                        <NewsTag v-if="article.tag" :tag="article.tag" />
                    </div>
                    <p class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ article.author || '—' }} · {{ when(article.published_at) }}
                    </p>
                </div>

                <AppButton variant="secondary" size="sm" :to="`/admin/news/${article.id}`">
                    {{ t('admin.edit') }}
                </AppButton>

                <AppButton variant="danger" size="sm" @click="remove(article)">
                    {{ t('admin.remove') }}
                </AppButton>
            </li>
        </ul>
    </div>
</template>
