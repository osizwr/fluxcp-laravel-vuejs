<script setup lang="ts">
import StateBlock from '@/components/ui/StateBlock.vue'
import { useNewsData } from '@/blocks/data'
import type { NewsProps } from '@/blocks/contracts'

/**
 * Fantasy — news.
 *
 * The legacy `cp_cmsnews` schema has no category, thumbnail or featured flag,
 * so this shows none: a category chip would have to be invented. "Featured"
 * here means the newest article, which is a layout choice rather than an
 * editorial one, and the composition opts into it.
 *
 * Only the excerpt is rendered, as text. The stored body is rich text from the
 * old editor, and interpolating it unescaped into a card is how a news CMS
 * becomes an XSS vector.
 */
const props = withDefaults(defineProps<NewsProps>(), {
    heading: 'From the heralds',
    limit: 3,
    featureFirst: true,
})

const news = useNewsData(props.limit)

function published(at: string | null): string {
    return at === null
        ? ''
        : new Date(at).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
}
</script>

<template>
    <section class="mx-auto max-w-6xl px-4 py-14" aria-labelledby="fantasy-news">
        <header class="mb-7 text-center">
            <h2 id="fantasy-news" class="text-xl font-semibold tracking-[0.1em] uppercase">
                {{ props.heading }}
            </h2>
            <hr class="theme-rule mx-auto mt-4 max-w-xs" />
        </header>

        <StateBlock
            v-if="news.state.error"
            variant="error"
            title="News unavailable"
            :description="news.state.error"
        />
        <StateBlock v-else-if="news.state.loading" variant="loading" title="Awaiting word…" />
        <StateBlock
            v-else-if="news.state.empty"
            variant="empty"
            title="No word from the heralds"
            description="Announcements posted by the server staff appear here."
        />

        <div v-else class="grid gap-3 md:grid-cols-3">
            <article
                v-for="(article, index) in news.articles"
                :key="article.id"
                class="panel flex flex-col p-5"
                :class="props.featureFirst && index === 0 ? 'theme-framed md:col-span-3' : ''"
            >
                <p class="text-[0.62rem] tracking-[0.14em] text-[var(--color-accent-300)] uppercase">
                    <template v-if="article.published_at">
                        {{ published(article.published_at) }}
                    </template>
                    <template v-if="article.author"> · {{ article.author }}</template>
                </p>

                <h3
                    class="mt-2 tracking-wide"
                    :class="props.featureFirst && index === 0 ? 'text-xl' : 'text-base'"
                >
                    {{ article.title }}
                </h3>

                <p class="mt-2 flex-1 text-sm text-[var(--text-secondary)]">{{ article.excerpt }}</p>

                <a
                    v-if="article.link"
                    :href="article.link"
                    rel="noreferrer noopener"
                    class="mt-3 inline-block text-[0.68rem] font-semibold tracking-[0.12em] text-[var(--color-accent-300)] uppercase underline decoration-dotted underline-offset-4"
                >
                    Read on
                </a>
            </article>
        </div>
    </section>
</template>
