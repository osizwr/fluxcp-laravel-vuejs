<script setup lang="ts">
import AppButton from '@/components/ui/AppButton.vue'
import { useHeroData } from '@/blocks/data'
import type { HeroProps } from '@/blocks/contracts'
import GameMark from '../components/GameMark.vue'

/**
 * Fantasy — the hero.
 *
 * A full visual block rather than a page header: the gate you arrive at.
 *
 * Everything atmospheric here is generated. The ground is layered radial
 * gradients, the pattern behind it is an inline SVG of concentric rings and
 * rune ticks, and the mark is drawn from GAME_SHORT_NAME. No binary artwork,
 * which means nothing to licence and nothing that breaks when the server is
 * renamed -- and emphatically no artwork from any existing game.
 *
 * Branding and both actions come from the hero contract, so this block decides
 * only how they look. The actions point at routes that exist; there is no
 * "create account" button while registration has no page.
 */
const props = withDefaults(defineProps<HeroProps>(), { showStatus: true })

const hero = useHeroData(props)
</script>

<template>
    <section class="relative isolate overflow-hidden">
        <!--
            Background, in three generated layers: a warm lamplit pool above, a
            cold arcane pool below, and a rune pattern between them. All
            decorative, so all hidden from assistive technology.
        -->
        <div class="absolute inset-0 -z-10" aria-hidden="true">
            <div
                class="absolute inset-0"
                style="
                    background-image:
                        radial-gradient(
                            900px 420px at 50% 0%,
                            color-mix(in oklab, var(--color-accent-600) 20%, transparent),
                            transparent 70%
                        ),
                        radial-gradient(
                            700px 380px at 50% 100%,
                            color-mix(in oklab, var(--theme-arcane) 16%, transparent),
                            transparent 70%
                        );
                "
            />

            <svg class="absolute inset-0 h-full w-full opacity-[0.07]" aria-hidden="true">
                <defs>
                    <pattern id="fantasy-runes" width="120" height="120" patternUnits="userSpaceOnUse">
                        <circle
                            cx="60"
                            cy="60"
                            r="46"
                            fill="none"
                            stroke="var(--color-accent-300)"
                            stroke-width="0.6"
                        />
                        <circle
                            cx="60"
                            cy="60"
                            r="30"
                            fill="none"
                            stroke="var(--color-accent-300)"
                            stroke-width="0.4"
                        />
                        <path
                            d="M60 6v12M60 102v12M6 60h12M102 60h12"
                            stroke="var(--color-accent-300)"
                            stroke-width="0.8"
                        />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#fantasy-runes)" />
            </svg>

            <!-- Vignette, so the type never sits on the brightest part. -->
            <div
                class="absolute inset-0"
                style="
                    background: radial-gradient(
                        120% 100% at 50% 40%,
                        transparent 40%,
                        var(--surface-page) 100%
                    );
                "
            />
        </div>

        <div class="mx-auto flex max-w-4xl flex-col items-center px-4 py-20 text-center sm:py-28">
            <GameMark :size="76" />

            <h1
                class="mt-7 text-3xl font-bold tracking-[0.05em] uppercase sm:text-5xl sm:tracking-[0.07em]"
            >
                {{ hero.title }}
            </h1>

            <p
                v-if="hero.description"
                class="mt-3 text-[0.78rem] tracking-[0.22em] text-[var(--color-accent-300)] uppercase sm:text-sm"
            >
                {{ hero.description }}
            </p>

            <hr class="theme-rule mt-8 w-full max-w-xs" />

            <p
                v-if="props.showStatus && hero.playersOnline !== null"
                class="mt-7 text-sm text-[var(--text-secondary)]"
            >
                <template v-if="hero.serversUp">
                    <span class="theme-live tabular font-semibold text-[var(--text-primary)]">
                        {{ hero.playersOnline.toLocaleString() }}
                    </span>
                    adventurers in the realm
                </template>
                <template v-else>
                    <span class="font-semibold text-[var(--color-down)]">The gates are shut</span>
                </template>
            </p>

            <div class="mt-8 flex flex-wrap justify-center gap-2.5">
                <AppButton
                    v-if="hero.primaryAction"
                    variant="primary"
                    :to="hero.primaryAction.to"
                    :href="hero.primaryAction.href"
                >
                    {{ hero.primaryAction.label }}
                </AppButton>
                <AppButton
                    v-if="hero.secondaryAction"
                    :to="hero.secondaryAction.to"
                    :href="hero.secondaryAction.href"
                >
                    {{ hero.secondaryAction.label }}
                </AppButton>
            </div>
        </div>
    </section>
</template>
