<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import CaptchaField from '../components/ui/CaptchaField.vue'
import FormField from '../components/ui/FormField.vue'
import { useAccounts } from '../composables/useAccounts'
import { useFormSubmit } from '../composables/useFormSubmit'
import { api } from '../services/api'
import { useAuthStore } from '../stores/auth'
import type { Account } from '../types/api'

const auth = useAuthStore()
const router = useRouter()

const {
    registrationEnabled,
    captchaOnRegistration,
    passwordHint,
    usernameHint,
    earliestBirthdate,
    config,
} = useAccounts()

const { errors, error, submitting, submit } = useFormSubmit()

const form = reactive({
    username: '',
    password: '',
    password_confirmation: '',
    email: '',
    email_confirmation: '',
    gender: 'M',
    birthdate: '',
    captcha: '',
})

const captcha = ref<InstanceType<typeof CaptchaField> | null>(null)

/** Set when the account was made but still needs its e-mail confirming. */
const awaitingConfirmation = ref<{ email: string; sent: boolean } | null>(null)

type RegistrationResponse = {
    data?: Account
    message?: string
    requires_confirmation?: boolean
    confirmation_sent?: boolean
}

async function register(): Promise<void> {
    const succeeded = await submit(async () => {
        const response = await api.post<RegistrationResponse>('auth/register', { ...form })

        if (response.requires_confirmation === true) {
            awaitingConfirmation.value = {
                email: form.email,
                sent: response.confirmation_sent !== false,
            }

            return
        }

        /*
         * No confirmation needed, so the server signed the account in with
         * this response. Loading the account here rather than posting the
         * password again keeps the credential out of a second request.
         */
        await auth.refresh()
        await router.push({ name: 'account' })
    })

    if (!succeeded && captchaOnRegistration.value) {
        // The challenge is consumed whether or not the answer was right, so a
        // failed submission needs a fresh image rather than a stale one.
        form.captcha = ''
        captcha.value?.refresh()
    }
}
</script>

<template>
    <div class="mx-auto max-w-md py-6">
        <template v-if="awaitingConfirmation">
            <h1 class="text-xl font-semibold tracking-tight">Check your e-mail</h1>

            <AlertMessage :tone="awaitingConfirmation.sent ? 'success' : 'warning'" class="mt-4">
                <template v-if="awaitingConfirmation.sent">
                    Your account has been created. Open the link we sent to
                    <strong>{{ awaitingConfirmation.email }}</strong> to activate it. You cannot
                    sign in until you do.
                </template>
                <template v-else>
                    Your account has been created, but we could not send the confirmation e-mail.
                    Ask for another one, and tell an administrator if it keeps failing.
                </template>
            </AlertMessage>

            <div class="mt-4 flex flex-wrap gap-2">
                <AppButton to="/resend-confirmation" variant="primary">
                    Send another link
                </AppButton>
                <AppButton to="/sign-in">Go to sign in</AppButton>
            </div>
        </template>

        <template v-else-if="!registrationEnabled">
            <h1 class="text-xl font-semibold tracking-tight">Registration is closed</h1>
            <p class="mt-1 text-sm text-[var(--text-secondary)]">
                New accounts are not being accepted at the moment.
            </p>
            <AppButton to="/" class="mt-4">Back to the front page</AppButton>
        </template>

        <template v-else>
            <h1 class="text-xl font-semibold tracking-tight">Create an account</h1>
            <p class="mt-0.5 mb-5 text-sm text-[var(--text-secondary)]">
                One account for the game and this website.
            </p>

            <AlertMessage v-if="error" tone="error" class="mb-4">{{ error }}</AlertMessage>

            <form class="panel space-y-4 p-4" novalidate @submit.prevent="register">
                <FormField label="Account name" :error="errors.username" :hint="usernameHint">
                    <template #default="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model.trim="form.username"
                            class="field-input"
                            type="text"
                            name="username"
                            autocomplete="username"
                            :minlength="config.username.minLength"
                            :maxlength="config.username.maxLength"
                            required
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <FormField label="E-mail address" :error="errors.email">
                    <template #default="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model.trim="form.email"
                            class="field-input"
                            type="email"
                            name="email"
                            autocomplete="email"
                            maxlength="39"
                            required
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <FormField label="Confirm e-mail address">
                    <template #default="{ id }">
                        <input
                            :id="id"
                            v-model.trim="form.email_confirmation"
                            class="field-input"
                            type="email"
                            name="email_confirmation"
                            autocomplete="email"
                            maxlength="39"
                            required
                        />
                    </template>
                </FormField>

                <FormField label="Password" :error="errors.password" :hint="passwordHint">
                    <template #default="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="form.password"
                            class="field-input"
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            :minlength="config.password.minLength"
                            :maxlength="config.password.maxLength"
                            required
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <FormField label="Confirm password">
                    <template #default="{ id }">
                        <input
                            :id="id"
                            v-model="form.password_confirmation"
                            class="field-input"
                            type="password"
                            name="password_confirmation"
                            autocomplete="new-password"
                            :maxlength="config.password.maxLength"
                            required
                        />
                    </template>
                </FormField>

                <FormField
                    label="Character gender"
                    :error="errors.gender"
                    hint="Your characters start with this. It can be changed in game."
                >
                    <template #default="{ id, describedBy }">
                        <select
                            :id="id"
                            v-model="form.gender"
                            class="field-input"
                            name="gender"
                            required
                            :aria-describedby="describedBy"
                        >
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                        </select>
                    </template>
                </FormField>

                <FormField
                    label="Date of birth"
                    :error="errors.birthdate"
                    :hint="
                        config.minimumAge > 0
                            ? `You must be at least ${config.minimumAge} years old.`
                            : undefined
                    "
                >
                    <template #default="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="form.birthdate"
                            class="field-input"
                            type="date"
                            name="birthdate"
                            :max="earliestBirthdate"
                            required
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </FormField>

                <CaptchaField ref="captcha" v-model="form.captcha" :error="errors.captcha" />

                <AppButton type="submit" variant="primary" block :loading="submitting">
                    Create account
                </AppButton>
            </form>

            <p class="mt-4 text-center text-sm text-[var(--text-secondary)]">
                Already have an account?
                <RouterLink to="/sign-in" class="underline underline-offset-2">Sign in</RouterLink>
            </p>
        </template>
    </div>
</template>
