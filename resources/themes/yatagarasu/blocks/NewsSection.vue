<script setup lang="ts">
import StateBlock from '@/components/ui/StateBlock.vue'
import { useNewsData } from '@/blocks/data'
import type { NewsProps } from '@/blocks/contracts'
import SectionHeading from '../components/SectionHeading.vue'

/**
 * Yatagarasu — the dispatches.
 *
 * Three columns inside one border, divided by hairlines: a dateline, a title
 * in tracked capitals, the excerpt in italic, and a prompt onward. The shared
 * border is what makes them read as a bulletin rather than as three cards.
 *
 * An entry links only where the operator gave it a link. There is no article
 * page in this application, so a card that linked to one would be dead
 * navigation; an entry without a link renders as the dispatch itself, which is
 * what the excerpt is for.
 */
const props = withDefaults(defineProps<NewsProps>(), {
    heading: 'Latest Dispatches',
    limit: 3,
    featureFirst: false,
})

const news = useNewsData(props.limit)

/** A dateline. An absent or unreadable date is omitted, not guessed at. */
function dateline(iso: string | null): string {
    if (iso === null) {
        return ''
    }

    const parsed = new Date(iso)

    return Number.isNaN(parsed.valueOf())
        ? ''
        : parsed.toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
}
</script>

<template>
    <section class="relative z-10 mx-auto max-w-7xl px-6 py-20" aria-labelledby="yata-news-title">
        <SectionHeading
            eyebrow="From the chroniclers"
            :title="props.heading"
            title-id="yata-news-title"
            class="mb-12"
        />

        <StateBlock
            v-if="news.state.error"
            variant="error"
            title="The chronicles are unavailable"
            :description="news.state.error"
        />
        <StateBlock
            v-else-if="news.state.loading && news.state.empty"
            variant="loading"
            title="Gathering dispatches…"
        />
        <StateBlock
            v-else-if="news.state.empty"
            variant="empty"
            title="Nothing has been written yet"
            description="Announcements from the operator appear here."
        />

        <div v-else class="yata-dispatches">
            <article v-for="article in news.articles" :key="article.id" class="yata-dispatch">
                <time v-if="dateline(article.published_at)" class="yata-dispatch__date">
                    {{ dateline(article.published_at) }}
                </time>
                <p v-else class="yata-dispatch__date">{{ article.author }}</p>

                <h3 class="yata-dispatch__title">{{ article.title }}</h3>

                <p class="yata-dispatch__excerpt">{{ article.excerpt }}</p>

                <a
                    v-if="article.link"
                    :href="article.link"
                    class="yata-dispatch__more"
                    rel="noreferrer noopener"
                >
                    Read more &rarr;
                </a>
            </article>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-dispatches {
    display: grid;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-panel);
    overflow: hidden;
}

@media (min-width: 768px) {
    :root[data-theme-slug='yatagarasu'] .yata-dispatches {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

:root[data-theme-slug='yatagarasu'] .yata-dispatch {
    display: flex;
    flex-direction: column;
    padding: 1.5rem;
    /* Stacked on a phone, so the divider runs along the bottom. */
    border-bottom: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-dispatch:last-child {
    border-bottom: 0;
}

@media (min-width: 768px) {
    /* Side by side, so it runs down the right instead. */
    :root[data-theme-slug='yatagarasu'] .yata-dispatch {
        border-bottom: 0;
        border-right: 1px solid var(--border-subtle);
    }

    :root[data-theme-slug='yatagarasu'] .yata-dispatch:last-child {
        border-right: 0;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-dispatch__date {
    display: block;
    margin-bottom: 0.75rem;
    font-family: var(--yata-font-tech);
    font-size: 0.75rem;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-dispatch__title {
    margin-bottom: 0.75rem;
    font-family: var(--font-display);
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.35;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--text-primary);
}

:root[data-theme-slug='yatagarasu'] .yata-dispatch__excerpt {
    flex: 1 1 auto;
    font-family: var(--yata-font-body);
    font-style: italic;
    font-size: 1rem;
    line-height: 1.7;
    color: var(--text-muted);
    /* Four lines, then stop. An excerpt that runs on is the article. */
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
    overflow: hidden;
}

:root[data-theme-slug='yatagarasu'] .yata-dispatch__more {
    margin-top: 1.25rem;
    font-family: var(--font-display);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--color-accent-500);
    text-decoration: none;
}

:root[data-theme-slug='yatagarasu'] .yata-dispatch__more:hover {
    text-decoration: underline;
    text-underline-offset: 3px;
}
</style>
