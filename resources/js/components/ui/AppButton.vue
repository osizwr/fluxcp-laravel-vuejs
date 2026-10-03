<script setup lang="ts">
import { computed } from 'vue'

/**
 * A button, a router link, or an external anchor -- styled identically.
 *
 * Navigation renders as a real link so it can be opened in a new tab, rather
 * than a div with a click handler. An external `href` also gets
 * rel="noreferrer noopener", which is easy to forget at each call site.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
        size?: 'sm' | 'md'
        type?: 'button' | 'submit'
        disabled?: boolean
        loading?: boolean
        /** Internal route. */
        to?: string
        /** External URL. Mutually exclusive with `to`. */
        href?: string
        block?: boolean
    }>(),
    {
        variant: 'secondary',
        size: 'md',
        type: 'button',
        disabled: false,
        loading: false,
        block: false,
    },
)

const variantClasses = {
    primary:
        'bg-[var(--color-accent-600)] text-white border-transparent hover:bg-[var(--color-accent-700)]',
    secondary:
        'bg-[var(--surface-raised)] text-[var(--text-primary)] border-[var(--border-strong)] hover:bg-[var(--surface-hover)]',
    ghost: 'bg-transparent text-[var(--text-secondary)] border-transparent hover:bg-[var(--surface-hover)]',
    danger: 'bg-[var(--color-down)] text-white border-transparent hover:brightness-95',
}

const sizeClasses = {
    sm: 'px-2.5 py-1 text-[0.8125rem]',
    md: 'px-3.5 py-1.5 text-sm',
}

const classes = computed(() => [
    'inline-flex items-center justify-center gap-1.5 rounded-[var(--radius-panel)] border font-medium transition-colors',
    variantClasses[props.variant],
    sizeClasses[props.size],
    props.block ? 'w-full' : '',
    props.disabled || props.loading ? 'cursor-not-allowed opacity-55' : '',
])
</script>

<template>
    <RouterLink v-if="props.to" :to="props.to" :class="classes">
        <slot />
    </RouterLink>

    <a
        v-else-if="props.href"
        :href="props.href"
        :class="classes"
        rel="noreferrer noopener"
        target="_blank"
    >
        <slot />
    </a>

    <button
        v-else
        :type="props.type"
        :class="classes"
        :disabled="props.disabled || props.loading"
        :aria-busy="props.loading || undefined"
    >
        <span
            v-if="props.loading"
            class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
            aria-hidden="true"
        />
        <slot />
    </button>
</template>
