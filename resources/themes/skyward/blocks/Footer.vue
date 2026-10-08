<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useGame } from '@/composables/useGame'
import GameMark from '../components/GameMark.vue'
import { useTranslation } from '@/i18n'

const { t } = useTranslation()

/**
 * Skyward — the footer.
 *
 * A flat white band closing the page, divided by a hairline rather than lifted
 * off it. That is the one place this skin stops floating: everything above is
 * a card on the sky, and a card with nothing below it reads as unfinished.
 *
 * Four columns: the mark with its social buttons, the link groups, and the
 * small print. Each group is a two-column grid filled left-to-right, which is
 * why the order in config/game.php reads across the rows.
 *
 * What is in the groups is not this block's business. The labels, the order
 * and the destinations are configuration, because they describe what the
 * server offers rather than how it looks -- and so they must survive a change
 * of skin. This block decides only how they are drawn.
 */
const { game, title } = useGame()

/*
 * Both defaulted, because the payload is embedded in the HTML while the code
 * reading it is in the bundle, and the two can briefly disagree: a visitor
 * holding a cached page from before `footer` and `legal` existed loads the new
 * bundle against the old payload. Without a default that is a TypeError during
 * render, and because this block is mounted by the layout, the error takes the
 * layout down with it -- the masthead and the sign-in dialog included. A
 * footer that renders short is a far better failure than a page that renders
 * nothing.
 */
const footer = computed(() => game.value.footer ?? { groups: [], socials: [] })

const legal = computed(
    () => game.value.legal ?? { disclaimer: null, copyright: null, credit: null },
)

/** Capitalised for the accessible name: `discord` -> `Discord`. */
function networkLabel(network: string): string {
    return network.charAt(0).toUpperCase() + network.slice(1)
}

const year = new Date().getFullYear()
</script>

<template>
    <footer class="sky-footer">
        <div class="sky-footer__card">
            <!-- The mark, and where else to find the server. -->
            <div class="sky-footer__brand">
                <RouterLink to="/" :aria-label="title" class="sky-footer__mark">
                    <GameMark :size="92" />
                </RouterLink>

                <ul
                    v-if="footer.socials.length > 0"
                    class="sky-footer__socials"
                    :aria-label="t('footer.followUs')"
                >
                    <li v-for="social in footer.socials" :key="social.network">
                        <!--
                            A network with no URL yet keeps its disc so the row
                            holds its shape, but is not an anchor. `aria-hidden`
                            because a button that goes nowhere is decoration,
                            and announcing it would promise a link that is not
                            there.
                        -->
                        <component
                            :is="social.href ? 'a' : 'span'"
                            :href="social.href ?? undefined"
                            :aria-label="social.href ? networkLabel(social.network) : undefined"
                            :aria-hidden="social.href ? undefined : 'true'"
                            :rel="social.href ? 'noreferrer noopener' : undefined"
                            :target="social.href ? '_blank' : undefined"
                            class="sky-footer__social"
                        >
                            <!--
                                A chat bubble rather than the Discord wordmark,
                                and a bare letterform for Facebook. Drawn inline
                                so the footer ships no binary asset to publish
                                or licence.
                            -->
                            <svg
                                v-if="social.network === 'discord'"
                                viewBox="0 0 96 96"
                                width="22"
                                height="22"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <path
                                    d="M20 15H76Q88 15 88 28V63Q88 76 74 76H44L22 89V76H20Q8 76 8 62V29Q8 15 20 15Z"
                                    fill="#5865f2"
                                    stroke="#3548c9"
                                    stroke-width="2"
                                    stroke-linejoin="round"
                                />
                                <circle cx="33" cy="46" r="5" fill="#fff" />
                                <circle cx="48" cy="46" r="5" fill="#fff" />
                                <circle cx="63" cy="46" r="5" fill="#fff" />
                            </svg>

                            <svg
                                v-else
                                viewBox="0 0 24 24"
                                width="20"
                                height="20"
                                fill="currentColor"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <path
                                    d="M13.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.6-1.5h1.6V4.4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.9 1.4-3.9 4v2.2H8v3h2.5V21h3z"
                                />
                            </svg>
                        </component>
                    </li>
                </ul>
            </div>

            <nav
                v-for="group in footer.groups"
                :key="group.heading"
                class="sky-footer__group"
                :aria-label="`Footer: ${group.heading}`"
            >
                <h2 class="sky-footer__heading">{{ group.heading }}</h2>

                <ul class="sky-footer__links">
                    <li v-for="link in group.links" :key="link.label">
                        <RouterLink v-if="link.to" :to="link.to" class="sky-footer__link">
                            {{ link.label }}
                        </RouterLink>

                        <a
                            v-else-if="link.href"
                            :href="link.href"
                            class="sky-footer__link"
                            rel="noreferrer noopener"
                            target="_blank"
                            >{{ link.label }}</a
                        >

                        <!--
                            A section the server has no page for yet. Drawn
                            identically so the footer shows the whole shape of
                            what is coming, but not as an anchor: a link that
                            navigates nowhere is a bug report waiting to be
                            filed. Give the entry a 'to' or a 'url' in
                            config/game.php and it becomes a real link.
                        -->
                        <span v-else class="sky-footer__link sky-footer__link--inert">
                            {{ link.label }}
                        </span>
                    </li>
                </ul>
            </nav>

            <div class="sky-footer__legal">
                <p v-if="legal.credit" class="sky-footer__credit">
                    {{ t('footer.designedBy') }}
                    <a
                        v-if="legal.credit.url"
                        :href="legal.credit.url"
                        rel="noreferrer noopener"
                        target="_blank"
                        >{{ legal.credit.name }}</a
                    >
                    <template v-else>{{ legal.credit.name }}</template>
                </p>

                <p v-if="legal.disclaimer" class="sky-footer__disclaimer">
                    {{ legal.disclaimer }}
                </p>

                <p v-if="legal.copyright" class="sky-footer__copyright">
                    © {{ year }} {{ legal.copyright }}
                </p>
            </div>
        </div>
    </footer>
</template>
