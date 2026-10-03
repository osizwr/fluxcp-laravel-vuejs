import { computed, type ComputedRef } from 'vue'
import { bootstrap } from '../theme/bootstrap'
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
    usernameHint: ComputedRef<string>
    earliestBirthdate: ComputedRef<string | undefined>
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
        usernameHint: computed(
            () =>
                `${config.value.username.minLength}–${config.value.username.maxLength} characters. ` +
                'Letters, numbers and underscores.',
        ),

        /**
         * The latest date of birth that satisfies the minimum age, for the
         * date input's `max`. Undefined when no minimum is configured, so the
         * attribute is left off rather than set to something meaningless.
         */
        earliestBirthdate: computed(() => {
            if (config.value.minimumAge <= 0) {
                return undefined
            }

            const latest = new Date()
            latest.setFullYear(latest.getFullYear() - config.value.minimumAge)

            return latest.toISOString().slice(0, 10)
        }),
    }
}

/**
 * The password policy as a sentence.
 *
 * Built from the configured numbers rather than hardcoded, so an operator who
 * changes the policy does not end up with a form describing the old one.
 */
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
