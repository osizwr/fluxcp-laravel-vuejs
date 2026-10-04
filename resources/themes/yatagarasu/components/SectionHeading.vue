<script setup lang="ts">
/**
 * The heading every section in this theme opens with.
 *
 * Eyebrow, title, diamond rule, optional lead. Centred by default and
 * left-aligned where a section is asymmetric, which is the only variation the
 * theme allows -- a page whose section headings each find their own
 * arrangement reads as several pages.
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
        <p v-if="eyebrow" class="yata-eyebrow">{{ eyebrow }}</p>

        <h2
            :id="titleId"
            class="yata-title mt-3 text-2xl sm:text-3xl lg:text-[2.1rem]"
        >
            {{ title }}
        </h2>

        <hr
            class="mt-4 w-full max-w-[13rem]"
            :class="align === 'center' ? 'yata-rule-diamond' : 'yata-rule'"
        />

        <p
            v-if="lead"
            class="mt-4 max-w-2xl text-[0.9375rem] leading-relaxed text-[var(--text-secondary)]"
        >
            {{ lead }}
        </p>
    </header>
</template>
