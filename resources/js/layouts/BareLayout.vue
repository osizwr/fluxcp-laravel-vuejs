<script setup lang="ts">
/**
 * No shell at all: the page gets the whole viewport.
 *
 * For pages where the surrounding furniture is the wrong answer rather than
 * merely unnecessary. An error page is the case this exists for -- a visitor
 * who has hit a dead end is not there to browse, and offering them a navbar,
 * a server-status strip and a footer of links is answering a question they
 * did not ask while burying the one thing they need.
 *
 * It renders the page and nothing else, so anything full-bleed here really is
 * full-bleed. There is no skip link, because there is nothing to skip.
 *
 * A theme replaces it with resources/themes/<slug>/layouts/BareLayout.vue, the
 * same as any other layout; a theme that wants its error pages inside the
 * shell points the `bare` role at its own AppLayout instead.
 */
</script>

<template>
    <main id="main"><slot /></main>
</template>
