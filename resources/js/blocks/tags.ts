/**
 * The word beside a headline, and how it is drawn.
 *
 * An editor types the tag free-hand -- the panel cannot know whether a server
 * posts auctions, guild weeks or patch notes, and a fixed list of five would
 * be wrong for most of them. What this file adds is recognition, not
 * restriction: a handful of words that nearly every server uses get a mark
 * and a colour, and anything else is drawn plainly rather than refused.
 *
 * Matching is on the lower-cased word, so News, news and NEWS are one tag. The
 * label drawn is always what the operator typed.
 */
export type NewsTagTone = 'neutral' | 'green' | 'blue' | 'violet' | 'amber' | 'red'

export interface NewsTagView {
    /** As the editor typed it, trimmed. Never the slug. */
    label: string
    /** A mark for a word this panel knows, or null for one it does not. */
    icon: string | null
    tone: NewsTagTone
}

/*
 * Emoji rather than drawn marks, which is a deliberate exception to the rest
 * of the interface: these sit inside the operator's own word, change with it,
 * and would otherwise need a new SVG committed for every tag a server invents.
 * They are decoration -- the label carries the meaning -- so nothing is lost
 * where a platform draws them differently or not at all.
 */
const KNOWN = [
    { slug: 'news', label: 'News', icon: '📣', tone: 'green' },
    { slug: 'update', label: 'Update', icon: '🔧', tone: 'blue' },
    { slug: 'patch', label: 'Patch', icon: '🔧', tone: 'blue' },
    { slug: 'event', label: 'Event', icon: '🎉', tone: 'amber' },
    { slug: 'auction', label: 'Auction', icon: '🔨', tone: 'violet' },
    { slug: 'maintenance', label: 'Maintenance', icon: '🛠️', tone: 'red' },
] as const satisfies ReadonlyArray<{
    slug: string
    label: string
    icon: string
    tone: NewsTagTone
}>

/** What the editor offers as suggestions, in the order they are offered. */
export const NEWS_TAG_SUGGESTIONS: readonly string[] = KNOWN.map((tag) => tag.label)

/* Typed on `string` rather than on the literal slugs, because what is looked
 * up here is whatever an operator typed. */
const BY_SLUG = new Map<string, (typeof KNOWN)[number]>(KNOWN.map((tag) => [tag.slug, tag]))

/**
 * How to draw one tag, or null for an article that has none.
 *
 * Null rather than a default tag: a list where every article is badged NEWS
 * is a list with a column of noise down the side of it. Somewhere that must
 * always show a badge -- the ticker labels its own strip -- asks for the
 * fallback by name instead.
 */
export function newsTag(raw: string | null | undefined): NewsTagView | null {
    const label = (raw ?? '').trim()

    if (label === '') {
        return null
    }

    const known = BY_SLUG.get(label.toLowerCase())

    return { label, icon: known?.icon ?? null, tone: known?.tone ?? 'neutral' }
}

/** The standing "News" badge, for a strip that has to be labelled something. */
export function defaultNewsTag(label: string): NewsTagView {
    return { label, icon: KNOWN[0].icon, tone: KNOWN[0].tone }
}
