<script setup lang="ts">
import OrnamentDivider from './OrnamentDivider.vue'

/**
 * The heading every section in this theme opens with.
 *
 * Eyebrow between two short rules, a title in tracked capitals, then the
 * ornament. Centred by default and left-aligned where a section is
 * asymmetric — the only variation the theme allows, because a page whose
 * headings each find their own arrangement reads as several pages.
 */
withDefaults(
    defineProps<{
        eyebrow?: string | null
        title: string
        lead?: string | null
        align?: 'center' | 'start'
        /** The id the section's aria-labelledby points at. */
        titleId?: string
    }>(),
    { eyebrow: null, lead: null, align: 'center', titleId: undefined },
)
</script>

<template>
    <header
        class="flex flex-col"
        :class="align === 'center' ? 'items-center text-center' : 'items-start text-left'"
    >
        <p v-if="eyebrow" class="yata-eyebrow">
            <!-- The flanking rules are part of the mark when it is centred;
                 beside a left-aligned title they would point at nothing. -->
            <span v-if="align === 'center'" class="yata-eyebrow__rule" />
            <span class="yata-eyebrow__text">{{ eyebrow }}</span>
            <span v-if="align === 'center'" class="yata-eyebrow__rule" />
        </p>

        <h2 :id="titleId" class="yata-title mt-4 text-3xl sm:text-4xl">
            {{ title }}
        </h2>

        <OrnamentDivider
            class="mt-5 w-full max-w-xs text-[var(--color-accent-500)]"
            :class="align === 'start' ? 'max-w-[12rem]' : ''"
        />

        <p v-if="lead" class="yata-prose mt-5 max-w-2xl text-lg text-[var(--text-secondary)]">
            {{ lead }}
        </p>
    </header>
</template>
