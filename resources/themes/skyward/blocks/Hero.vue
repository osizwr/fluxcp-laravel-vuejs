<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import { useHeroData } from '@/blocks/data'
import { useAppearance } from '@/composables/useAppearance'
import { useGame } from '@/composables/useGame'
import { preloadState, preloadedUrl } from '@/composables/usePreload'
import type { HeroProps } from '@/blocks/contracts'
import GameMark from '../components/GameMark.vue'
import { useTranslation } from '@/i18n'

const { t } = useTranslation()

/**
 * Skyward — the hero.
 *
 * A full viewport of the server's own key art, with the masthead and the
 * ticker floating on top of it. It carries the `.sky-hero` class, which is
 * what cancels the padding the layout put on `.sky-main` and re-adds it as the
 * hero's own: the result is that the artwork runs up behind the floating cards
 * while this block's own text still clears them. Both halves reference the one
 * measured variable, so a taller masthead moves them together.
 *
 * Three layers, in order:
 *
 *   1. A generated sky -- layered gradients and two cloud banks. This is the
 *      ground everything else sits on, and on an installation with no media it
 *      is the whole background. No binary artwork, so there is nothing to
 *      licence and nothing that breaks when the server is renamed.
 *
 *   2. The video slot, if the file is there -- a different file by
 *      appearance, because this skin has a day and a night and the same
 *      footage cannot be both. It requests its path and simply stays with
 *      the generated sky when that 404s, so supplying the art is dropping a
 *      file into `public/videos/hero/` rather than editing this theme, and a
 *      server that has only published the daylight loop gets the generated
 *      sky after dark instead of a broken element.
 *
 *   3. A neutral scrim under the type, and a fade at the bottom edge so the
 *      artwork hands off to the page colour instead of ending on a hard
 *      line. The scrim is white, not page-blue: a wash in the page colour
 *      casts its tint over the whole frame and delivers the key art
 *      desaturated.
 *
 * Branding and both actions come from the hero contract, so this block decides
 * only how they look.
 */
const props = withDefaults(defineProps<HeroProps>(), { showStatus: true })

const hero = useHeroData(props)

const { game } = useGame()

/*
 * Whether the supplied logo stands in for the heading.
 *
 * Only when this hero is showing the server's own name. A lockup drawn with
 * "Yatagarasu" on it is the right heading for the front page and the wrong one
 * for a hero that was handed a different title, so an explicit `title` prop
 * keeps the typeset heading and the mark stays a mark.
 */
const logoIsHeading = computed(() => game.value.logo !== null && props.title === undefined)

/* -------------------------------------------------------------------------- */
/* The two loops                                                              */
/* -------------------------------------------------------------------------- */

/**
 * Day and night, as two files and two elements.
 *
 * Two files rather than one graded two ways: a night hero is a different shot
 * -- different light, different sky, different hour -- and dimming the
 * daytime footage with a filter produces a grey afternoon rather than a
 * night. The paths are conventions of this theme, not configuration, for the
 * same reason the daylight one already was: dropping a file at the path is
 * how a server supplies it.
 *
 * ---------------------------------------------------------------------------
 * Why both are mounted rather than one element changing its source
 * ---------------------------------------------------------------------------
 *
 * Swapping `src` on a single element -- or keying one element on the source,
 * which amounts to the same thing -- cannot be made to look like a fade. The
 * incoming file has to be fetched, demuxed and decoded before it has a frame
 * to show, and on a hero loop that is tens of megabytes: the page finishes
 * fading to night while the video slot is still empty, and the footage snaps
 * in somewhere behind it. No amount of easing hides that, because there is
 * nothing to ease *to* yet.
 *
 * So both elements exist and the switch is only a change of opacity. The
 * browser already has both decoders primed, so the cross-fade starts on the
 * frame the button was pressed, and the two shots dissolve into each other
 * the way the rest of the page does.
 *
 * The cost is the second file. It is not paid at the door: the inactive loop
 * is not mounted until the preload gate has cleared, so the page opens
 * exactly as fast as it did with one video and the second arrives quietly
 * behind a landing page the visitor is already reading.
 */
const DAY = '/videos/hero/skyward-hero.mp4'
const NIGHT = '/videos/hero/skyward-night.mp4'

/*
 * Fixed order, always, so the two sit in a stable stacking order and the
 * switch changes nothing but which one is opaque. Ordering them active-first
 * would reorder the DOM on every toggle, which is a second thing changing
 * during a transition whose whole job is to change one.
 */
const LOOPS = [DAY, NIGHT] as const

const { appearance } = useAppearance()
const { settled } = preloadState()

const videoSource = computed(() => (appearance.value === 'dark' ? NIGHT : DAY))

/*
 * Which loops have failed to load, by path.
 *
 * A set rather than a flag, because there are two of them and they fail
 * independently: a server that published the daylight hero and not the night
 * one should keep its daylight hero rather than lose both. The generated sky
 * stands in for whichever is missing.
 *
 * It starts empty, so an installation that has the media never flashes the
 * generated sky first. That sky is the fallback rather than a labelled
 * placeholder: a box captioned with a file path is the right placeholder for
 * a slot the layout is built around, and the wrong one for a background that
 * already looks finished without it.
 */
const failedSources = ref<ReadonlySet<string>>(new Set())

const mountedLoops = computed(() =>
    LOOPS.filter((source) => {
        if (reducedMotion.value || failedSources.value.has(source)) {
            return false
        }

        // The one being shown, always. The other once the gate is down.
        return source === videoSource.value || settled.value
    }),
)

/*
 * There is no "the video is missing" flag any more, and nothing needs one:
 * `mountedLoops` has already dropped whatever failed, so a loop that 404s
 * simply is not among the elements rendered and the generated sky behind
 * them is what shows. A separate flag would have been a second answer to a
 * question the filter above has already settled.
 */
function onVideoError(source: string): void {
    failedSources.value = new Set(failedSources.value).add(source)
}

/* -------------------------------------------------------------------------- */
/* Keeping them in step                                                       */
/* -------------------------------------------------------------------------- */

/**
 * The elements, by the path each is playing.
 *
 * Collected with a function ref rather than an array one because what is
 * wanted is "the night element", and an array's indices shift the moment a
 * loop drops out of `mountedLoops` for having failed.
 */
const elements = ref<Record<string, HTMLVideoElement | null>>({})

function register(source: string, element: Element | null): void {
    elements.value[source] = element as HTMLVideoElement | null
}

/**
 * Put one loop where the other has got to.
 *
 * Both shots pan across the same valley, so the switch only reads as the
 * light changing if the camera is in the same place either side of it.
 * Starting the night loop from zero while the day one was forty seconds in
 * swings the view back across the valley in the middle of a cross-fade,
 * which is the one thing a cross-fade cannot cover for.
 *
 * Wrapped by the incoming clip's own duration rather than clamped, because
 * the two files need not be the same length -- and when they are, the modulo
 * is the identity and the two are frame-aligned.
 *
 * Seeking is skipped inside a quarter of a second. A seek flushes the decode
 * pipeline, and doing that on a difference nobody can see would cost a
 * stutter to correct nothing.
 */
function align(target: HTMLVideoElement | null, reference: HTMLVideoElement | null): void {
    if (target === null || reference === null) {
        return
    }

    const { duration } = target
    const at =
        Number.isFinite(duration) && duration > 0
            ? reference.currentTime % duration
            : reference.currentTime

    if (Math.abs(target.currentTime - at) > 0.25) {
        target.currentTime = at
    }
}

/**
 * Only the visible loop plays.
 *
 * The other is held paused on the matching frame. Leaving both running would
 * keep them in step for free and cost a second video being decoded forever
 * on a page nobody asked to play two -- which on a laptop is a fan and on a
 * phone is the battery. Pausing costs an explicit seek at each switch, which
 * is the function above, and that is the cheaper side of the trade.
 *
 * `play()` rejects when it is interrupted by the next switch, which is not a
 * failure and has nothing to report.
 */
function applyPlayback(): void {
    const active = elements.value[videoSource.value] ?? null

    for (const source of LOOPS) {
        const element = elements.value[source] ?? null

        if (element === null) {
            continue
        }

        if (source === videoSource.value) {
            void element.play().catch(() => undefined)
        } else {
            align(element, active)
            element.pause()
        }
    }
}

/**
 * Align before the switch, not after.
 *
 * A watcher with the default `pre` flush runs ahead of the component's own
 * re-render, so this happens while the outgoing loop is still the one
 * playing and still holds the position the incoming one needs.
 */
watch(videoSource, (next, previous) => {
    align(elements.value[next] ?? null, elements.value[previous] ?? null)
    applyPlayback()
})

/**
 * A loop that has just become playable joins wherever the other one is.
 *
 * `loadedmetadata` is the first moment `duration` is known and the first at
 * which a seek is allowed, which makes this the earliest the join can
 * happen. Two different arrivals end up here and both need it:
 *
 *   - the standby loop mounting seconds after the active one, which on its
 *     own would sit seconds behind for the rest of the visit, and
 *   - the loop somebody switched *to* before it had any metadata, where the
 *     alignment attempted at the switch landed on an element that was not
 *     ready to be seeked and quietly did nothing.
 *
 * So it aligns against the other element whichever of the two this is, and
 * the only thing it checks is that the other one has a position worth
 * copying. At zero there is nothing to join.
 */
function onVideoReady(source: string): void {
    const other = elements.value[source === DAY ? NIGHT : DAY] ?? null

    if (other !== null && other.currentTime > 0) {
        align(elements.value[source] ?? null, other)
    }

    applyPlayback()
}

/*
 * Motion is a preference the browser already knows, and a looping background
 * is exactly what it is about.
 *
 * Under it the video is not rendered at all, rather than rendered and paused.
 * A paused <video> still has to download before it can show a frame, and a
 * hero loop is a large file to pull down for a still -- so the generated sky,
 * which is finished-looking and costs nothing, stands in instead.
 */
const reducedMotion = ref(false)
let motionQuery: MediaQueryList | null = null

function onMotionChange(event: MediaQueryListEvent): void {
    reducedMotion.value = event.matches
}

onMounted(() => {
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
    reducedMotion.value = motionQuery.matches
    motionQuery.addEventListener('change', onMotionChange)
})

onBeforeUnmount(() => motionQuery?.removeEventListener('change', onMotionChange))
</script>

<template>
    <section
        class="sky-hero sky-hero--full relative isolate flex flex-col items-center justify-center overflow-hidden"
    >
        <div class="absolute inset-0 -z-10" aria-hidden="true">
            <!-- Daylight: brightest just above the horizon, as it is outdoors. -->
            <div
                class="absolute inset-0"
                style="
                    background-image:
                        radial-gradient(
                            140% 95% at 50% -20%,
                            color-mix(in oklab, var(--color-accent-400) 52%, transparent),
                            transparent 62%
                        ),
                        radial-gradient(
                            90% 60% at 50% 105%,
                            color-mix(in oklab, var(--surface-raised) 85%, transparent),
                            transparent 70%
                        );
                "
            />

            <!-- Two cloud banks, offset so the horizon does not read as a rule. -->
            <div
                class="absolute inset-x-0 bottom-0 h-2/3"
                style="
                    background-image:
                        radial-gradient(
                            38% 52% at 18% 88%,
                            color-mix(in oklab, var(--surface-raised) 75%, transparent),
                            transparent 72%
                        ),
                        radial-gradient(
                            44% 46% at 82% 95%,
                            color-mix(in oklab, var(--surface-raised) 68%, transparent),
                            transparent 72%
                        );
                "
            />

            <!--
                Decorative, so it is muted, uncontrolled and hidden from
                assistive technology. `playsinline` because without it iOS
                takes any playing video fullscreen, which would throw the
                visitor out of the page on arrival.
            -->
            <!--
                Both loops, stacked, with opacity deciding which one is the
                hero. Never keyed on the source: that would replace the
                element and put the decode back in front of the fade, which
                is the thing this arrangement exists to avoid.

                `preload="auto"` on both, because the inactive one is only
                mounted once the gate is down -- it is buffering behind a
                page that is already readable, and having it ready is the
                whole point of mounting it early.

                No `autoplay`. Which one runs is decided in script, or the
                standby loop would start itself on arrival and have to be
                caught and paused a frame later.
            -->
            <video
                v-for="source in mountedLoops"
                :key="source"
                :ref="(element) => register(source, element as Element | null)"
                class="sky-hero__video"
                :class="{ 'sky-hero__video--standby': source !== videoSource }"
                :src="preloadedUrl(source)"
                muted
                loop
                playsinline
                preload="auto"
                aria-hidden="true"
                tabindex="-1"
                @loadedmetadata="onVideoReady(source)"
                @error="onVideoError(source)"
            />

            <!--
                A neutral scrim, and the handoff to the section below.

                The scrim is mixed from `--surface-raised`, which is pure
                white, rather than `--surface-page`, which is a pale blue.
                That distinction is the whole point: a wash in the page colour
                casts its blue across the frame and delivers the key art
                desaturated, where a white one only lifts what is already
                there.

                It is not decoration. Measured against this footage the
                description sits at 2.7:1 unaided -- below the 4.5:1 that body
                text needs, and 1.0:1 over the darker passages, which is
                invisible. The scrim is sized to the text block and fades out
                well before the edges, so the artwork reads everywhere it is
                not carrying type.

                The last two stops are structural: without them the artwork
                meets the page on a hard horizontal edge.
            -->
            <div
                class="absolute inset-0"
                style="
                    background:
                        radial-gradient(
                            52% 38% at 50% 55%,
                            color-mix(in oklab, var(--surface-raised) 66%, transparent),
                            transparent 78%
                        ),
                        linear-gradient(
                            to bottom,
                            transparent 58%,
                            color-mix(in oklab, var(--surface-page) 72%, transparent) 88%,
                            var(--surface-page) 100%
                        );
                "
            />
        </div>

        <div class="mx-auto flex max-w-3xl flex-col items-center px-4 text-center">
            <!--
                The logo is the heading when there is one. It is wrapped in the
                h1 rather than set beside it so the page keeps exactly one
                level-one heading, and the heading's accessible name comes from
                the image's alt text -- which is the server's name -- instead of
                being announced twice.
            -->
            <!--
                Sized against both axes on purpose. Width alone is not the
                binding constraint: the hero is exactly one viewport tall, so on
                a short laptop it is the *height* that decides whether the
                buttons below still clear the fold. `min(vw, vh)` lets the mark
                grow on a tall screen and stand down on a shallow one, and the
                clamp keeps it from vanishing on a phone or running past the
                text column on a very large display.
            -->
            <h1 v-if="logoIsHeading">
                <GameMark size="clamp(10rem, min(46vw, 40vh), 24rem)" />
            </h1>

            <template v-else>
                <GameMark :size="84" />

                <h1
                    class="mt-6 font-[family-name:var(--font-display)] text-4xl font-extrabold tracking-tight text-[var(--text-primary)] sm:text-6xl"
                >
                    {{ hero.title }}
                </h1>
            </template>

            <p
                v-if="hero.description"
                class="mt-4 max-w-xl text-base text-[var(--text-secondary)] sm:text-lg"
            >
                {{ hero.description }}
            </p>

            <p
                v-if="props.showStatus && hero.playersOnline !== null"
                class="mt-6 inline-flex items-center gap-2 rounded-full bg-[var(--surface-raised)] px-4 py-1.5 text-sm text-[var(--text-secondary)] shadow-sm"
            >
                <span
                    class="size-2 rounded-full"
                    :class="hero.serversUp ? 'bg-[var(--color-up)]' : 'bg-[var(--color-down)]'"
                    aria-hidden="true"
                />
                <template v-if="hero.serversUp">
                    <span class="font-extrabold text-[var(--text-primary)]">
                        {{ hero.playersOnline.toLocaleString() }}
                    </span>
                    {{ t('server.playersOnline').toLowerCase() }}
                </template>
                <template v-else>{{ t('server.offline') }}</template>
            </p>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
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
