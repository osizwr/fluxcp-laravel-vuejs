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
