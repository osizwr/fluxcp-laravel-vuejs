<script setup lang="ts">
import { computed } from 'vue'
import StateBlock from '@/components/ui/StateBlock.vue'
import { useNewsData } from '@/blocks/data'
import type { NewsProps } from '@/blocks/contracts'
import ArtPlaceholder from '../components/ArtPlaceholder.vue'
import SectionHeading from '../components/SectionHeading.vue'

/**
 * Yatagarasu — the chronicles.
 *
 * Editorial rather than blog cards: the newest entry runs wide with its
 * artwork, and the rest are a list of datelines beside it. That arrangement
 * says which is current, which a row of three identical cards cannot.
 *
 * `featureFirst` means "the newest", not an editorial choice — the legacy
 * schema has no featured flag and inventing one here would be the block
 * deciding something the operator did not.
 *
 * An entry links only where the operator gave it a link. There is no
 * article page in this application, so a card that linked to one would be
 * dead navigation; an entry without a link renders as the dispatch itself,
 * which is what the excerpt is for.
 */
const props = withDefaults(defineProps<NewsProps>(), {
    heading: 'Latest Chronicles',
    limit: 3,
    featureFirst: true,
})

const news = useNewsData(props.limit)

const lead = computed(() => (props.featureFirst ? news.value.articles[0] : undefined))
const rest = computed(() =>
    props.featureFirst ? news.value.articles.slice(1) : news.value.articles,
)

/** A dateline. Absent dates are omitted rather than shown as a placeholder. */
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
    <section class="yata-air" aria-labelledby="yata-news-title">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:py-20">
            <SectionHeading
                eyebrow="Dispatches"
                :title="props.heading"
                title-id="yata-news-title"
                align="start"
            />

            <div class="mt-10">
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
                <!-- Constrained, so it sits under the heading rather than
                     adrift in the middle of a left-aligned section. -->
                <StateBlock
                    v-else-if="news.state.empty"
                    class="max-w-xl"
                    variant="empty"
                    title="Nothing has been written yet"
                    description="Announcements from the operator appear here."
                />

                <div v-else class="yata-news">
                    <!-- The newest, with artwork. -->
                    <article
                        v-if="lead"
                        class="yata-plate yata-news__lead"
                        :class="{ 'yata-plate--interactive': lead.link }"
                    >
                        <component
                            :is="lead.link ? 'a' : 'div'"
                            v-bind="lead.link ? { href: lead.link, rel: 'noreferrer noopener' } : {}"
                            class="yata-news__lead-link"
                        >
                            <div class="yata-news__art">
                                <ArtPlaceholder
                                    path="/images/news/latest.webp"
                                    :alt="lead.title"
                                    ratio="aspect-[16/9]"
                                />
                            </div>

                            <div class="yata-news__lead-body">
                                <p class="yata-news__dateline">
                                    <span v-if="dateline(lead.published_at)">
                                        {{ dateline(lead.published_at) }}
                                    </span>
                                    <span class="yata-news__by">{{ lead.author }}</span>
                                </p>

                                <h3 class="yata-news__lead-title">{{ lead.title }}</h3>

                                <p class="yata-news__excerpt">{{ lead.excerpt }}</p>

                                <p v-if="lead.link" class="yata-news__more" aria-hidden="true">
                                    Read the dispatch &rarr;
                                </p>
                            </div>
                        </component>
                    </article>

                    <!-- The rest, as datelines. -->
                    <ol v-if="rest.length > 0" class="yata-news__list">
                        <li v-for="article in rest" :key="article.id">
                            <component
                                :is="article.link ? 'a' : 'div'"
                                v-bind="
                                    article.link
                                        ? { href: article.link, rel: 'noreferrer noopener' }
                                        : {}
                                "
                                class="yata-news__row"
                                :class="{ 'is-static': !article.link }"
                            >
                                <p class="yata-news__dateline">
                                    <span v-if="dateline(article.published_at)">
                                        {{ dateline(article.published_at) }}
                                    </span>
                                    <span class="yata-news__by">{{ article.author }}</span>
                                </p>

                                <h3 class="yata-news__row-title">{{ article.title }}</h3>

                                <p class="yata-news__excerpt yata-news__excerpt--tight">
                                    {{ article.excerpt }}
                                </p>
                            </component>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
</template>

<style>
:root[data-theme-slug='yatagarasu'] .yata-news {
    display: grid;
    gap: 1rem;
}

@media (min-width: 1000px) {
    :root[data-theme-slug='yatagarasu'] .yata-news {
        /* The lead takes the larger share; the datelines sit beside it. */
        grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr);
        align-items: start;
    }
}

:root[data-theme-slug='yatagarasu'] .yata-news__lead {
    overflow: hidden;
}

:root[data-theme-slug='yatagarasu'] .yata-news__lead-link {
    display: block;
    text-decoration: none;
}

:root[data-theme-slug='yatagarasu'] .yata-news__art {
    overflow: hidden;
}

:root[data-theme-slug='yatagarasu'] .yata-news__art figure {
    border: 0;
    border-bottom: 1px solid var(--border-subtle);
    transition: scale 480ms var(--yata-ease);
}

:root[data-theme-slug='yatagarasu'] .yata-news__lead:hover .yata-news__art figure {
    scale: 1.03;
}

:root[data-theme-slug='yatagarasu'] .yata-news__lead-body {
    padding: 1.75rem;
}

:root[data-theme-slug='yatagarasu'] .yata-news__dateline {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--color-accent-600);
}

:root[data-theme-slug='yatagarasu'] .yata-news__by {
    color: var(--text-muted);
}

:root[data-theme-slug='yatagarasu'] .yata-news__lead-title {
    margin-top: 0.875rem;
    font-family: var(--font-display);
    font-size: 1.5rem;
    font-weight: 600;
    line-height: 1.25;
    letter-spacing: 0.02em;
    color: var(--yata-ivory);
    text-wrap: balance;
}

:root[data-theme-slug='yatagarasu'] .yata-news__excerpt {
    margin-top: 0.875rem;
    font-size: 0.9375rem;
    line-height: 1.65;
    color: var(--text-secondary);
    /* Three lines, then stop. An excerpt that runs on is the article. */
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
    overflow: hidden;
}

:root[data-theme-slug='yatagarasu'] .yata-news__excerpt--tight {
    -webkit-line-clamp: 2;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

:root[data-theme-slug='yatagarasu'] .yata-news__more {
    margin-top: 1.25rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--color-accent-500);
}

/* ---- The dateline list ------------------------------------------------- */

:root[data-theme-slug='yatagarasu'] .yata-news__list {
    margin: 0;
    padding: 0;
    list-style: none;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-panel);
}

:root[data-theme-slug='yatagarasu'] .yata-news__list li + li {
    border-top: 1px solid var(--border-subtle);
}

:root[data-theme-slug='yatagarasu'] .yata-news__row {
    display: block;
    padding: 1.5rem;
    text-decoration: none;
    border-left: 2px solid transparent;
    transition:
        background-color 200ms var(--yata-ease),
        border-color 200ms var(--yata-ease);
}

/*
 * A gold edge on approach rather than a lift. These are rows in a list, and a
 * row that rises off the page takes the rows around it with it.
 */
:root[data-theme-slug='yatagarasu'] .yata-news__row:not(.is-static):hover {
    background-color: color-mix(in oklab, var(--yata-gold) 4%, transparent);
    border-left-color: var(--color-accent-500);
}

:root[data-theme-slug='yatagarasu'] .yata-news__row-title {
    margin-top: 0.6rem;
    font-family: var(--font-display);
    font-size: 1.0625rem;
    font-weight: 600;
    line-height: 1.3;
    color: var(--yata-ivory);
}

@media (prefers-reduced-motion: reduce) {
    :root[data-theme-slug='yatagarasu'] .yata-news__art figure,
    :root[data-theme-slug='yatagarasu'] .yata-news__lead:hover .yata-news__art figure {
        transition: none;
        scale: 1;
    }
}
</style>
