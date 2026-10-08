import { computed, ref, type ComputedRef, type Ref } from 'vue'
import en from './locales/en'
import tl from './locales/tl'
import ptBR from './locales/pt-BR'
import th from './locales/th'
import ms from './locales/ms'
import id from './locales/id'

/**
 * Translation.
 *
 * Hand-rolled rather than brought in, for the same reason the rest of this
 * application's shared behaviour is: what is needed here is a lookup and a
 * substitution, and a library would bring a message compiler, a plural-rule
 * engine and a bundler plugin to do it. Every language this panel ships is
 * handled by "one" and "other", which is twelve lines below.
 *
 * Where it does follow the convention of a library: a missing key falls back
 * to English rather than rendering blank, and an unknown key renders as itself
 * rather than as an empty string, because a visitor seeing `account.title` has
 * been told something is broken and a visitor seeing nothing has not.
 *
 * -------------------------------------------------------------------------
 * Who decides the language
 * -------------------------------------------------------------------------
 *
 * The server does, and this agrees with it. The locale arrives in the
 * bootstrap payload like everything else the server decides, so the first
 * paint is already in the right language -- picking it here would mean
 * rendering English and then correcting it.
 *
 * Changing it writes a cookie and reloads, rather than swapping the strings in
 * place. That is deliberate: a good half of what a visitor reads comes back
 * from the API -- validation errors, ban reasons, confirmation mail -- and
 * those are translated by Laravel against the same cookie. Switching the
 * client alone would leave the page in Tagalog and its error messages in
 * English.
 */

export type Locale = 'en' | 'tl' | 'pt-BR' | 'th' | 'ms' | 'id'

/** What a locale calls itself. Never translated: a language picker is read by
 *  somebody who does not yet read the current language. */
export const LOCALE_NAMES: Record<Locale, string> = {
    en: 'English',
    tl: 'Tagalog',
    'pt-BR': 'Português',
    th: 'ไทย',
    ms: 'Bahasa Melayu',
    id: 'Bahasa Indonesia',
}

const MESSAGES: Record<Locale, Messages> = { en, tl, 'pt-BR': ptBR, th, ms, id }

/** A locale file: nested groups of strings, or of further groups. */
export interface Messages {
    [key: string]: string | PluralForms | Messages
}

/** A string that changes with a count. Tagalog, Thai, Malay and Indonesian do
 *  not inflect the noun for number, so both forms are identical in those four,
 *  and that is correct rather than lazy. */
export interface PluralForms {
    one: string
    other: string
}

export const COOKIE = 'panel_locale'

const active = ref<Locale>('en')

/*
 * Checked against LOCALE_NAMES rather than against a list written out here, so
 * that adding a language is one edit rather than two that can disagree.
 */
function isLocale(value: unknown): value is Locale {
    return typeof value === 'string' && value in LOCALE_NAMES
}

/**
 * Adopt the locale the server chose.
 *
 * Called once at start-up from the bootstrap payload. Anything unrecognised
 * leaves English in place rather than throwing: a payload that arrived
 * garbled should cost the visitor their language, not the page.
 */
export function initLocale(value: unknown): void {
    if (isLocale(value)) {
        active.value = value
    }
}

/** Walks a dotted key through the nested groups. */
function lookup(messages: Messages, key: string): string | PluralForms | undefined {
    let node: string | PluralForms | Messages | undefined = messages

    for (const part of key.split('.')) {
        if (typeof node !== 'object' || node === null || !(part in node)) {
            return undefined
        }

        node = (node as Messages)[part]
    }

    return typeof node === 'object' && !('one' in node) ? undefined : (node as string | PluralForms)
}

/**
 * `{name}` and `{count}` are replaced from the values given.
 *
 * A placeholder with no value is left as written rather than blanked, so a
 * sentence missing a number reads as obviously wrong instead of quietly
 * losing a word.
 */
function interpolate(template: string, values: Record<string, string | number>): string {
    return template.replace(/\{(\w+)\}/g, (whole, name: string) =>
        name in values ? String(values[name]) : whole,
    )
}

export function translate(
    key: string,
    values: Record<string, string | number> = {},
    locale: Locale = active.value,
): string {
    const found = lookup(MESSAGES[locale], key) ?? lookup(MESSAGES.en, key)

    if (found === undefined) {
        return key
    }

    const template =
        typeof found === 'string' ? found : values.count === 1 ? found.one : found.other

    return interpolate(template, values)
}

export function useTranslation(): {
    /** `t('auth.signIn')`, `t('password.capitals', { count: 2 })`. */
    t: (key: string, values?: Record<string, string | number>) => string
    locale: Readonly<Ref<Locale>>
    locales: ComputedRef<Array<{ code: Locale; name: string }>>
    setLocale: (next: Locale) => void
} {
    return {
        t: (key, values = {}) => translate(key, values, active.value),
        locale: active,
        locales: computed(() =>
            (Object.keys(LOCALE_NAMES) as Locale[]).map((code) => ({
                code,
                name: LOCALE_NAMES[code],
            })),
        ),
        setLocale(next: Locale): void {
            if (next === active.value) {
                return
            }

            /*
             * A year, path-wide, and `SameSite=Lax` so it survives arriving
             * from a link on a forum or a Discord message -- which is how most
             * visitors reach a game panel.
             */
            document.cookie = `${COOKIE}=${encodeURIComponent(next)}; path=/; max-age=31536000; samesite=lax`

            /*
             * Reload rather than swap in place, so the server re-renders with
             * the new locale and the API starts answering in it. See the note
             * at the top.
             */
            window.location.reload()
        },
    }
}
