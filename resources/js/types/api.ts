/**
 * Shapes returned by the panel's API.
 *
 * Written by hand against app/Http/Resources rather than generated, so that a
 * change to a resource shows up as a type error here instead of as undefined
 * at runtime.
 */

export type AccountLevel = -2 | -1 | 0 | 1 | 2 | 99 | 9999

export interface AccountGroup {
    id: number
    name: string
    level: AccountLevel
    label: string
    is_staff: boolean
}

export interface AccountState {
    code: number
    label: string | null
    permanently_banned: boolean
    temporarily_banned: boolean
    ban_expires_at: string | null
    expired: boolean
}

export interface Account {
    id: number
    username: string
    email: string
    gender: 'M' | 'F' | 'S'
    group: AccountGroup
    state: AccountState
    is_vip: boolean
    character_slots: number
    login_count: number
    last_login_at: string | null
    birthdate: string | null
    credits: number
    characters?: Character[]
}

export interface CharacterGuild {
    id: number
    name?: string | null
}

export interface Character {
    id: number
    account_id?: number
    name: string
    slot: number
    job_id: number
    job_name: string
    base_level: number
    job_level: number
    zeny: number
    online: boolean
    /** Withheld unless the viewer holds ViewOnlinePosition. */
    map?: string
    guild?: CharacterGuild
    is_married: boolean
    pending_deletion: boolean
    deletion_final_at: string | null
    last_login_at: string | null
}

/** A card in a slot, or the one real card on a forged item. */
export interface ItemCard {
    item_id: number
    name: string | null
}

/** One stack in an inventory, a cart or a guild store. */
export interface ItemStack {
    id: number
    item_id: number
    name: string | null
    type: string | null
    slots: number
    amount: number
    refine: number
    identified: boolean
    broken: boolean
    equipped: boolean
    equip_location: number
    favourite: boolean
    bound: number
    expires_at: string | null
    cards: ItemCard[]
    /** How many cards are slotted beyond the item's own slot count. */
    cards_over: number
    created_by_char_id: number | null
    created_by: string | null
    creation_kind: 'forged' | 'brewed' | null
    random_options: { id: number; value: number }[]
}

/** A friend or a party member; both lists carry the same shape. */
export interface CharacterCompanion {
    id: number
    name: string
    job_id: number
    base_level: number
    job_level: number
    online: boolean
    guild: { id: number; name: string; emblem_url: string | null } | null
}

/** What comes beside a character on its own page. */
export interface CharacterBelongings {
    preferences: Record<string, boolean>
    inventory: ItemStack[]
    cart: ItemStack[]
    friends: CharacterCompanion[]
    party: CharacterCompanion[]
    /** False for a viewer without SeeUnknownItems, whose lists are filtered. */
    shows_unidentified: boolean
}

/* -------------------------------------------------------------------------- */
/* Guilds                                                                     */
/* -------------------------------------------------------------------------- */

export interface GuildSummary {
    id: number
    name: string
    level: number
    average_level: number
    members: number
    max_members: number
    emblem_id: number
    master: string
    emblem_url: string | null
}

export interface GuildMember {
    id: number
    name: string
    job_id: number
    base_level: number
    job_level: number
    online: boolean
    position: number
    position_name: string
    position_mode: number
    /** The share of experience this rank pays into the guild, as a percentage. */
    guild_tax: number
    devotion: number
}

export interface GuildPosition {
    position: number
    name: string
    mode: number
    guild_tax: number
}

export interface GuildExpulsion {
    account_id: number
    name: string
    reason: string
}

/**
 * One guild's page.
 *
 * Deliberately not an extension of GuildSummary: `members` is a count in the
 * listing and the roster here, so the two responses are different shapes and
 * are declared as such rather than forced into one.
 */
export interface Guild {
    id: number
    name: string
    level: number
    average_level: number
    max_members: number
    emblem_id: number
    master: string
    emblem_url: string | null
    member_count: number
    members_online: number
    experience: number
    next_experience: number
    skill_points: number
    notice: { title: string; body: string }
    members: GuildMember[]
    allies: { id: number; name: string }[]
    enemies: { id: number; name: string }[]
    castles: number[]
    expulsions: GuildExpulsion[]
    positions: GuildPosition[]
    /** Null when the viewer may not see the store, which is not the same as empty. */
    storage: ItemStack[] | null
}

export interface CharMapServerStatus {
    key: string
    name: string
    login_server_up: boolean
    char_server_up: boolean
    map_server_up: boolean
    playable: boolean
    players_online: number
    players_peak: number | null
    woe_active: boolean
}

export interface ServerGroupStatus {
    key: string
    name: string
    login_server_up: boolean
    players_online: number
    servers: CharMapServerStatus[]
}

export interface ServerStatusResponse {
    data: ServerGroupStatus[]
    meta: {
        players_online: number
        measured_at: string
        cache_seconds: number
    }
}

export interface RankingEntry {
    rank: number
    character: {
        id: number
        name: string
        job_id: number
        job_name: string
        base_level: number
        job_level: number
        zeny: number
    }
    guild: {
        id: number
        name: string | null
        emblem_id: number
    } | null
}

export interface Paginated<T> {
    data: T[]
    links: { first: string | null; last: string | null; prev: string | null; next: string | null }
    meta: {
        current_page: number
        from: number | null
        last_page: number
        per_page: number
        to: number | null
        total: number
    }
}

/**
 * Aggregate counts over the game database.
 *
 * No uptime: rAthena records no start time the panel can read, so the figure
 * would have to be invented.
 */
export interface ServerStatistics {
    accounts: number
    characters: number
    guilds: number
    players_online: number
}

export interface ClassDistributionEntry {
    job_id: number
    job_name: string
    characters: number
}

export interface NewsArticle {
    id: number
    title: string
    excerpt: string
    author: string
    link: string | null
    published_at: string | null
    updated_at: string | null
    /** Rich text, sent only when a single article was requested. */
    body?: string
}

/* -------------------------------------------------------------------------- */
/* Item and monster databases                                                 */
/* -------------------------------------------------------------------------- */

export interface Item {
    id: number
    name: string
    aegis_name: string
    type: string | null
    subtype: string | null
    /** True when the row came from the server's own item_db2 rather than stock. */
    is_custom: boolean
    origin_table: string | null
    price: { buy: number | null; sell: number | null }
    weight: number | null
    attack: number | null
    defense: number | null
    range: number | null
    slots: number
    weapon_level: number | null
    view: number | null
    gender: string | null
    equip_level: { min: number | null; max: number | null }
    refineable: boolean
    /** Decoded from rAthena's one-column-per-attribute layout by the server. */
    equip_locations: string[]
    jobs: string[]
    classes: string[]
    trade_restrictions: string[]
    flags: string[]
    /** Only sent on the detail view; the listing omits it. */
    script?: string | null
    /**
     * The description imported from the client's itemInfo.lua, on the detail
     * view only and only when the operator has it turned on.
     *
     * Server-generated HTML: the item's own text is escaped and the only
     * markup is a colour span built from matched hex digits, which is why this
     * one field is rendered rather than interpolated as text.
     */
    description?: string | null
}

export interface Monster {
    id: number
    name: string
    aegis_name: string
    level: number
    hp: number
    sp: number
    experience: { base: number; job: number; mvp: number }
    attack: { min: number; max: number }
    defense: number
    magic_defense: number
    size: number | null
    race: number | null
    element: number | null
    is_mvp: boolean
    modes: string[]
    is_custom: boolean
    origin_table: string | null
}

/** The search vocabulary, so the client does not keep its own copy. */
export interface ItemVocabulary {
    types: Record<string, string>
    locations: Record<string, string>
    jobs: Record<string, string>
    classes: Record<string, string>
    operators: string[]
    comparable: string[]
    sortable: string[]
}

/** What a listing endpoint reports about the sort that was applied. */
export interface ListMeta {
    sortable: string[]
    sort: string | null
    direction: 'asc' | 'desc'
}
