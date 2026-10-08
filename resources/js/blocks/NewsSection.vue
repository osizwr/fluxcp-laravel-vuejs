<script setup lang="ts">
import { computed } from 'vue'
import StateBlock from '../components/ui/StateBlock.vue'
import { useNewsData } from './data'
import type { NewsProps } from './contracts'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * Recent news, from the legacy CMS table.
 *
 * Note what is absent: no category and no thumbnail. The legacy `cp_cmsnews`
 * schema has neither, so showing them would mean inventing them.
 *
 * Only the excerpt is rendered, as text. The stored body is rich text from the
 * old editor, and interpolating it unescaped into a listing is how a news CMS
 * becomes an XSS vector.
 */
const props = withDefaults(defineProps<NewsProps>(), {
    limit: 3,
    featureFirst: false,
})

const news = useNewsData(props.limit)

function published(at: string | null): string {
    return at === null ? '' : new Date(at).toLocaleDateString()
}

/* The default lives here rather than in defineProps, which is hoisted out
 * of setup() and so cannot call t(). */
const headingText = computed(() => props.heading ?? t('news.heading'))
</script>

<template>
    <section class="mx-auto max-w-6xl px-4 py-10" aria-labelledby="block-news">
        <h2 id="block-news" class="mb-4 text-lg font-semibold">{{ headingText }}</h2>

        <StateBlock
            v-if="news.state.error"
            variant="error"
            :title="t('news.unavailable')"
            :description="news.state.error"
        />
        <StateBlock v-else-if="news.state.loading" variant="loading" :title="t('common.loading')" />
        <StateBlock
            v-else-if="news.state.empty"
            variant="empty"
            :title="t('news.empty')"
            :description="t('news.emptyBody')"
        />

        <div v-else class="grid gap-3 md:grid-cols-3">
            <article
                v-for="(article, index) in news.articles"
                :key="article.id"
                class="panel p-4"
                :class="props.featureFirst && index === 0 ? 'md:col-span-3' : ''"
            >
                <h3 class="font-semibold">{{ article.title }}</h3>

                <p class="mt-1 text-[0.75rem] text-[var(--text-muted)]">
                    <template v-if="article.published_at">
                        {{ published(article.published_at) }}
                    </template>
                    <template v-if="article.author"> · {{ article.author }}</template>
                </p>

                <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ article.excerpt }}</p>

                <a
                    v-if="article.link"
                    :href="article.link"
                    rel="noreferrer noopener"
                    class="mt-2 inline-block text-sm font-medium text-[var(--color-accent-600)] underline"
                >
                    {{ t('news.readMore') }}
                </a>
            </article>
        </div>
    </section>
</template>
