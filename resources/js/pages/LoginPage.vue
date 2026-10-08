<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import ConfirmCodeForm from '../components/ui/ConfirmCodeForm.vue'
import FormField from '../components/ui/FormField.vue'
import PasswordInput from '../components/ui/PasswordInput.vue'
import { useAccounts } from '../composables/useAccounts'
import { ApiError } from '../services/api'
import { useAuthStore } from '../stores/auth'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

// Only offer what the operator has actually enabled, so neither link leads to
// a page that refuses it.
const { registrationEnabled, passwordResetEnabled } = useAccounts()

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ username: '', password: '', remember: false })
const errors = reactive<Record<string, string>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

/** The account name whose confirmation is still outstanding, if any. */
const awaiting = ref<string | null>(null)
const confirmed = ref(false)

/**
 * Back to the sign-in form from the confirmed screen.
 *
 * Both flags have to go: the confirmed screen is shown ahead of the code form,
 * so dropping only the pending account would leave the page on a success
 * message with no way off it.
 */
function returnToSignIn(): void {
    awaiting.value = null
    confirmed.value = false
}

async function submit(): Promise<void> {
    submitting.value = true
    generalError.value = null
    Object.keys(errors).forEach((key) => delete errors[key])

    try {
        await auth.login({ ...form })

        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null

        // Only ever a path within this application. Following an arbitrary
        // value from the query string would be an open redirect.
        await router.push(redirect?.startsWith('/') ? redirect : { name: 'account' })
    } catch (error) {
        /*
         * An account that exists and is one step from working is not a failed
         * sign-in, it is an unfinished registration. Offering the code form is
         * the only useful answer; repeating "this account needs confirming"
         * and leaving them on the sign-in page is not.
         */
        if (error instanceof ApiError && error.reason === 'auth.failure.pending_confirmation') {
            awaiting.value = form.username

            return
        }

        if (error instanceof ApiError && (error.isValidation || error.isRateLimited)) {
            for (const [field, messages] of Object.entries(error.errors)) {
                errors[field] = messages[0]
            }

            if (Object.keys(error.errors).length === 0) {
                generalError.value = error.message
            }
        } else {
            generalError.value = t('common.genericError')
        }
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <!--
        Same swap as on registration: the row that confirmed the account has
        nothing left to ask once it has been accepted.
    -->
    <div v-if="confirmed" class="mx-auto max-w-sm py-6">
        <h1 class="text-xl font-semibold tracking-tight">
            {{ t('register.otpConfirmedTitle') }}
        </h1>

        <AlertMessage tone="success" class="mt-4">
            {{ t('register.otpConfirmed') }}
        </AlertMessage>

        <p class="mt-4 text-center text-sm">
            <button type="button" class="underline underline-offset-2" @click="returnToSignIn">
                {{ t('auth.goToSignIn') }}
            </button>
        </p>
    </div>

    <div v-else-if="awaiting !== null" class="mx-auto max-w-sm py-6">
        <ConfirmCodeForm :username="awaiting" @confirmed="confirmed = true" />
    </div>

    <div v-else class="mx-auto max-w-sm py-6">
        <h1 class="text-xl font-semibold tracking-tight">{{ t('nav.signIn') }}</h1>
        <p class="mt-0.5 mb-5 text-sm text-[var(--text-secondary)]">
            <!-- {{ t('auth.signInSubtitle') }} -->
        </p>

        <AlertMessage v-if="generalError" tone="error" class="mb-4">
            {{ generalError }}
        </AlertMessage>

        <form class="panel space-y-4 p-4" novalidate @submit.prevent="submit">
            <FormField :label="t('auth.accountName')" :error="errors.username">
                <template #default="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model.trim="form.username"
                        class="field-input"
                        type="text"
                        name="username"
                        autocomplete="username"
                        maxlength="23"
                        required
                        :aria-invalid="invalid || undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </FormField>

            <FormField :label="t('auth.password')" :error="errors.password">
                <template #default="{ id, invalid, describedBy }">
                    <PasswordInput
                        :id="id"
                        v-model="form.password"
                        name="password"
                        autocomplete="current-password"
                        required
                        :aria-invalid="invalid || undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </FormField>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.remember" type="checkbox" class="size-4 rounded" />
                {{ t('auth.keepSignedIn') }}
            </label>

            <AppButton type="submit" variant="primary" block :loading="submitting">
                {{ t('nav.signIn') }}
            </AppButton>

            <p v-if="passwordResetEnabled" class="text-center text-sm">
                <RouterLink
                    to="/forgot-password"
                    class="text-[var(--text-secondary)] underline underline-offset-2 hover:text-[var(--text-primary)]"
                >
                    {{ t('auth.forgotPassword') }}
                </RouterLink>
            </p>
        </form>

        <p v-if="registrationEnabled" class="mt-4 text-center text-sm text-[var(--text-secondary)]">
            {{ t('auth.needAccount') }}
            <RouterLink to="/register" class="underline underline-offset-2">
                {{ t('auth.createOne') }}
            </RouterLink>
        </p>

        <!-- <p class="mt-2 text-center text-[0.8125rem] text-[var(--text-muted)]">
            {{ t('auth.waitingConfirmation') }}
            <RouterLink to="/resend-confirmation" class="underline underline-offset-2">
                {{ t('auth.sendItAgain') }}
            </RouterLink>
        </p> -->
    </div>
</template>
