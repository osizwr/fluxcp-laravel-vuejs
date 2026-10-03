import { computed, type ComputedRef } from 'vue'
import { bootstrap } from '../theme/bootstrap'
import type { GameConfig, ThemeConfig } from '../types/bootstrap'

/**
 * Game branding and the active theme's identity.
 *
 * Components read the game's name from here rather than hardcoding it, so the
 * same panel can front a differently branded server by changing GAME_NAME. A
 * theme consumes these values; it does not define them.
 */
export function useGame(): {
    game: ComputedRef<GameConfig>
    theme: ComputedRef<ThemeConfig>
    /** Full name, or the short name where space is tight. */
    title: ComputedRef<string>
    /** Links the operator actually configured, as [key, url] pairs. */
    links: ComputedRef<Array<[string, string]>>
    supports: (feature: string) => boolean
} {
    const payload = bootstrap()

    const game = computed(() => payload.game)
    const theme = computed(() => payload.theme)

    return {
        game,
        theme,
        title: computed(() => payload.game.name || payload.game.shortName),
        links: computed(() => Object.entries(payload.game.links) as Array<[string, string]>),
        supports: (feature: string) => payload.theme.supports[feature] === true,
    }
}
