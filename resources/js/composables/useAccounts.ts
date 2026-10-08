import { computed, type ComputedRef } from 'vue'
import { bootstrap } from '../theme/bootstrap'
import { translate as t } from '../i18n'
import type { AccountsConfig, PasswordPolicy } from '../types/bootstrap'

/**
 * What the account forms are allowed to offer, and the rules they can state.
 *
 * All of it comes from the server's bootstrap payload, so a theme or a page
 * never decides policy -- it only renders what the operator configured. The
 * server enforces every one of these again on submission; this is here so a
 * form can say the rules up front instead of rejecting somebody afterwards.
 */
export function useAccounts(): {
    config: ComputedRef<AccountsConfig>
    registrationEnabled: ComputedRef<boolean>
    passwordResetEnabled: ComputedRef<boolean>
    captchaOnRegistration: ComputedRef<boolean>
    captchaSelfHosted: ComputedRef<boolean>
    captchaSiteKey: ComputedRef<string | null>
    passwordHint: ComputedRef<string>
} {
    const config = computed(() => bootstrap().accounts)

    return {
        config,
        registrationEnabled: computed(() => config.value.registrationEnabled),
        passwordResetEnabled: computed(() => config.value.passwordResetEnabled),
        captchaOnRegistration: computed(() => config.value.captcha.onRegistration),
        captchaSelfHosted: computed(() => config.value.captcha.selfHosted),
        captchaSiteKey: computed(() => config.value.captcha.siteKey),
        passwordHint: computed(() => describePasswordPolicy(config.value.password)),
    }
}

/**
 * The password policy as a sentence.
 *
 * Built from the configured numbers rather than hardcoded, so an operator who
 * changes the policy does not end up with a form describing the old one.
 */
/** One rule of the password policy, and whether what has been typed satisfies it. */
export interface PasswordRequirement {
    key: string
    label: string
    met: boolean
}

/**
 * The policy as a checklist, measured against a password as it is typed.
 *
 * Deliberately next to `describePasswordPolicy`, which turns the same policy
 * into a sentence: the two must never disagree about what the rules are, and
 * keeping them apart is how that happens.
 *
 * The tests here mirror `RathenaAccountService::validatePassword` exactly,
 * because a checklist that is merely close is worse than none -- a green tick
 * against a rule the server then rejects is the panel telling the visitor a
 * lie. So the classes are ASCII, matching the server's `/[A-Z]/`, `/[a-z]/`,
 * `/[0-9]/` and `/[^A-Za-z0-9]/`, the count is of occurrences rather than of
 * presence, and the account-name test is a case-insensitive substring, which is
 * what `stripos` does.
 *
 * The server validates all of this again. This exists so somebody can see which
 * rule they have not met yet, not to decide whether they may proceed.
 *
 * @param username Checked only when the policy forbids it and the page knows
 *                 it; the reset-password form, reached from an e-mailed token,
 *                 does not.
 */
export function passwordRequirements(
    policy: PasswordPolicy,
    password: string,
    username = '',
): PasswordRequirement[] {
    const occurrences = (pattern: RegExp): number => (password.match(pattern) ?? []).length

    /*
     * Code points, not UTF-16 units. The server measures with `mb_strlen`,
     * where an emoji counts as one character and `'...'.length` would say two.
     */
    const length = [...password].length

    const requirements: PasswordRequirement[] = [
        {
            key: 'length',
            label: t('password.length', { min: policy.minLength, max: policy.maxLength }),
            met: length >= policy.minLength && length <= policy.maxLength,
        },
    ]

    const counted: Array<[string, number, RegExp]> = [
        ['uppercase', policy.minUppercase, /[A-Z]/g],
        ['lowercase', policy.minLowercase, /[a-z]/g],
        ['numbers', policy.minNumbers, /[0-9]/g],
        ['symbols', policy.minSymbols, /[^A-Za-z0-9]/g],
    ]

    /*
     * The count drives both the number in the sentence and which plural form
     * is used, which is why it is handed to the translator rather than being
     * pasted into an English noun with an "s" on the end. Tagalog does not
     * inflect these nouns at all; Portuguese does.
     */
    for (const [key, required, pattern] of counted) {
        if (required > 0) {
            requirements.push({
                key,
                label: t(`password.${key}`, { count: required }),
                met: occurrences(pattern) >= required,
            })
        }
    }

    if (!policy.allowUsernameInside && username !== '') {
        requirements.push({
            key: 'username',
            label: t('password.noAccountName'),
            met: !password.toLowerCase().includes(username.toLowerCase()),
        })
    }

    /*
     * An empty field has met nothing. Strictly it does satisfy "contains no
     * part of your account name", but ticking a rule green before a character
     * has been typed reads as the checklist being broken.
     */
    return password === '' ? requirements.map((rule) => ({ ...rule, met: false })) : requirements
}

export function describePasswordPolicy(policy: PasswordPolicy): string {
    const requirements: string[] = []

    const counted: Array<[number, string]> = [
        [policy.minUppercase, 'capital letter'],
        [policy.minLowercase, 'lowercase letter'],
        [policy.minNumbers, 'number'],
        [policy.minSymbols, 'symbol'],
    ]

    for (const [count, noun] of counted) {
        if (count > 0) {
            requirements.push(`${count} ${noun}${count === 1 ? '' : 's'}`)
        }
    }

    // The maximum is not a style choice: rAthena's password column is
    // varchar(32), so anything longer could never be matched.
    let sentence = `${policy.minLength}–${policy.maxLength} characters`

    if (requirements.length > 0) {
        sentence += `, including at least ${formatList(requirements)}`
    }

    return `${sentence}.`
}

function formatList(items: string[]): string {
    if (items.length <= 1) {
        return items.join('')
    }

    return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`
}
