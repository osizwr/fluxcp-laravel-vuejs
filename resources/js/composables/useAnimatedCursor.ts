import { onUnmounted } from 'vue'

/**
 * The game's own cursor: an arrow that turns, and a glove for anything
 * clickable.
 *
 * `cursor` is not an animatable CSS property, so the spin cannot come from a
 * keyframe rule -- browsers ignore it in `@keyframes` entirely. This advances a
 * custom property on a timer instead, and the rules in `app.css` decide what to
 * do with it, so the hotspots, the fallbacks and which elements count as
 * clickable all stay in the stylesheet. This file only has to know which frame
 * is next.
 *
 * The glove and the arrow at rest do not animate -- their stills are published
 * as custom properties for the same reason the frames are: this is the one
 * place that knows whether `image-set()` can be used, and asking twice invites
 * the two answers to drift apart.
 *
 * The arrow replaces only the *inherited* default, so a disabled control asking
 * for `not-allowed` and a text field's I-beam both still win on their own
 * rules.
 *
 * Three things switch the whole thing off, because a decorative cursor is never
 * worth more than the pointer somebody actually needs:
 *
 *  - a coarse or hover-less pointer, where there is no cursor to decorate and
 *    the sprites would be dead weight on a phone;
 *  - `prefers-reduced-motion`, which holds the first frame rather than giving
 *    up the cursor altogether, since the art is not what was objected to --
 *    the stills are unaffected, having nothing to reduce;
 *  - a hidden tab, so a backgrounded panel is not repainting a cursor that
 *    nobody is pointing at.
 *
 * The first two are watched rather than read once, so plugging in a mouse or
 * changing the system setting takes effect without a reload.
 */

/** How many `arrow-NN.png` frames sit in `public/images/cursors/`. */
const FRAME_COUNT = 6

/**
 * One frame per 125ms through the turn. Faster starts to flicker at the edge of
 * vision while you are trying to read the page behind it; much slower stops
 * reading as a spin and becomes a cursor that keeps changing shape.
 */
const FRAME_MS = 125

/**
 * Frame 0 is held a little longer, so the arrow comes to rest facing forward
 * before it goes round again instead of tumbling continuously. It is the frame
 * the cursor is recognisably itself in, and the beat is what makes the turn
 * read as a flourish rather than as a spinner saying the page is busy.
 */
const REST_FRAME = 0
const REST_MS = 700

function frameDuration(frame: number): number {
    return frame === REST_FRAME ? REST_MS : FRAME_MS
}

const ROOT_CLASS = 'cursor-animated'
const FRAME_PROPERTY = '--cursor-frame'
const HAND_PROPERTY = '--cursor-hand'
const HAND_PRESS_PROPERTY = '--cursor-hand-press'
const STILL_PROPERTY = '--cursor-arrow-still'

const HAND = 'hand'
const HAND_PRESS = 'hand-press'
const STILL = 'arrow-still'

/** A pointer that can hover and can be placed precisely: a mouse or trackpad. */
const POINTER_QUERY = '(hover: hover) and (pointer: fine)'
const MOTION_QUERY = '(prefers-reduced-motion: reduce)'

function assetPath(name: string, density: '' | '@2x'): string {
    return `/images/cursors/${name}${density}.png`
}

function arrowFrame(frame: number): string {
    return `arrow-${String(frame).padStart(2, '0')}`
}

/**
 * Cursor images are drawn at their intrinsic size in CSS pixels, so a 32px file
 * on a 2x display is upscaled by the compositor and the pixel art turns to
 * mush. `image-set()` offers the browser a nearest-neighbour double instead.
 *
 * Asked rather than assumed: Safari only dropped the `-webkit-` prefix in 17,
 * and a `cursor` built from a function the browser cannot parse is invalid at
 * computed-value time -- which discards the whole declaration instead of
 * falling back to an earlier one, taking the cursor with it. Older browsers get
 * the single-density art and a slightly soft pointer, which is the right way
 * round.
 */
function supportsImageSet(): boolean {
    return (
        typeof CSS !== 'undefined' &&
        typeof CSS.supports === 'function' &&
        CSS.supports('cursor', `image-set(url("${assetPath(HAND, '')}") 1x) 0 0, auto`)
    )
}

export function useAnimatedCursor() {
    const root = document.documentElement

    const hiDpi = supportsImageSet()
    const density: '' | '@2x' = hiDpi && window.devicePixelRatio > 1 ? '@2x' : ''

    function image(name: string): string {
        return hiDpi
            ? `image-set(url("${assetPath(name, '')}") 1x, url("${assetPath(name, '@2x')}") 2x)`
            : `url("${assetPath(name, '')}")`
    }

    const frames = Array.from({ length: FRAME_COUNT }, (_, frame) => image(arrowFrame(frame)))

    const pointer = window.matchMedia(POINTER_QUERY)
    const motion = window.matchMedia(MOTION_QUERY)

    let timer: number | null = null
    let frame = 0
    let preloaded = false

    /*
     * Warms the browser cache before the first swap. Without it the opening
     * turn of the spin fetches six files one at a time, and a frame that has
     * not arrived yet leaves the pointer blank for an instant. The glove is
     * included because the first hover has the same problem, over a button
     * somebody is about to click.
     */
    function preload(): void {
        if (preloaded) {
            return
        }

        preloaded = true

        const names = [
            ...Array.from({ length: FRAME_COUNT }, (_, index) => arrowFrame(index)),
            HAND,
            HAND_PRESS,
        ]

        for (const name of names) {
            new Image().src = assetPath(name, density)
        }
    }

    function show(next: number): void {
        frame = next % FRAME_COUNT
        root.style.setProperty(FRAME_PROPERTY, frames[frame])
    }

    function stopSpinning(): void {
        if (timer !== null) {
            window.clearTimeout(timer)
            timer = null
        }
    }

    /*
     * A timeout that re-arms rather than an interval, because the frames are
     * not all shown for the same length of time -- an interval can only offer
     * one period for the whole loop. Each hop is scheduled off the duration of
     * the frame now on screen, so the rest beat falls out of the same timer
     * instead of needing a case of its own.
     */
    function scheduleNext(): void {
        timer = window.setTimeout(() => {
            show(frame + 1)
            scheduleNext()
        }, frameDuration(frame))
    }

    function startSpinning(): void {
        stopSpinning()

        if (motion.matches || document.visibilityState !== 'visible') {
            return
        }

        scheduleNext()
    }

    function detach(): void {
        stopSpinning()
        root.classList.remove(ROOT_CLASS)
        root.style.removeProperty(FRAME_PROPERTY)
        root.style.removeProperty(HAND_PROPERTY)
        root.style.removeProperty(HAND_PRESS_PROPERTY)
        root.style.removeProperty(STILL_PROPERTY)
    }

    /*
     * Re-run whenever one of the answers might have changed. Every property is
     * set before the class goes on, deliberately: a `cursor` whose `var()` has
     * no value is invalid at computed-value time, which would flash the
     * ordinary pointer for a tick on the way in.
     */
    function apply(): void {
        if (!pointer.matches) {
            detach()
            return
        }

        preload()
        show(motion.matches ? 0 : frame)
        root.style.setProperty(HAND_PROPERTY, image(HAND))
        root.style.setProperty(HAND_PRESS_PROPERTY, image(HAND_PRESS))
        /*
         * Published but not applied anywhere, and deliberately left out of the
         * preload above. Nothing in the panel rests: the spin runs even over a
         * text field and a disabled control. This is here for a theme that has
         * made something genuinely inert and wants the arrow to stop over it,
         * and a theme that uses it pays the one fetch itself.
         */
        root.style.setProperty(STILL_PROPERTY, image(STILL))
        root.classList.add(ROOT_CLASS)
        startSpinning()
    }

    function start(): void {
        pointer.addEventListener('change', apply)
        motion.addEventListener('change', apply)
        document.addEventListener('visibilitychange', apply)

        apply()
    }

    onUnmounted(() => {
        pointer.removeEventListener('change', apply)
        motion.removeEventListener('change', apply)
        document.removeEventListener('visibilitychange', apply)

        detach()
    })

    return { start }
}
