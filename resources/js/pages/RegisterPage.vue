<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import ConfirmCodeForm from '../components/ui/ConfirmCodeForm.vue'
import CaptchaField from '../components/ui/CaptchaField.vue'
import FormField from '../components/ui/FormField.vue'
import PasswordInput from '../components/ui/PasswordInput.vue'
import PasswordRequirements from '../components/ui/PasswordRequirements.vue'
import { useAccounts } from '../composables/useAccounts'
import { useFormSubmit } from '../composables/useFormSubmit'
import { api } from '../services/api'
import { useAuthStore } from '../stores/auth'
import type { Account } from '../types/api'
import { useTranslation } from '../i18n'

const auth = useAuthStore()
const router = useRouter()

const { registrationEnabled, captchaOnRegistration, config } = useAccounts()

const { errors, error, submitting, submit } = useFormSubmit()

const form = reactive({
    username: '',
    password: '',
    password_confirmation: '',
    email: '',
    captcha: '',
})

const captcha = ref<InstanceType<typeof CaptchaField> | null>(null)

/** Set when the account was made but still needs its e-mail confirming. */
const awaitingConfirmation = ref<{ username: string; email: string; sent: boolean } | null>(null)

/** Set once the code has been accepted, so the page can offer the next step. */
const confirmed = ref(false)

type RegistrationResponse = {
    data?: Account
    message?: string
    requires_confirmation?: boolean
    confirmation_sent?: boolean
}

const { t } = useTranslation()

async function register(): Promise<void> {
    const succeeded = await submit(async () => {
        const response = await api.post<RegistrationResponse>('auth/register', { ...form })

        if (response.requires_confirmation === true) {
            awaitingConfirmation.value = {
                username: form.username,
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
        <!--
            Straight into the code form rather than a page telling somebody to
            go and check their e-mail. The account exists and is one step from
            working; that step is the useful thing to show.
        -->
        <!--
            The code row goes once the code has been accepted. Filling the last
            box is now the whole of the submission, so leaving an empty row
            under a heading that still says to check your e-mail invites
            somebody to type a second code into a form that has finished.
        -->
        <template v-if="confirmed">
            <h1 class="text-xl font-semibold tracking-tight">
                {{ t('register.otpConfirmedTitle') }}
            </h1>

            <AlertMessage tone="success" class="mt-4">
                {{ t('register.otpConfirmed') }}
            </AlertMessage>

            <div class="mt-4">
                <AppButton to="/sign-in" variant="primary" block>
                    {{ t('auth.goToSignIn') }}
                </AppButton>
            </div>
        </template>

        <template v-else-if="awaitingConfirmation">
            <AlertMessage v-if="!awaitingConfirmation.sent" tone="warning" class="mb-4">
                {{ t('register.confirmNotSent') }}
            </AlertMessage>

            <ConfirmCodeForm
                :username="awaitingConfirmation.username"
                :email="awaitingConfirmation.email"
                :code-sent="awaitingConfirmation.sent"
                @confirmed="confirmed = true"
            />
        </template>

        <template v-else-if="!registrationEnabled">
            <h1 class="text-xl font-semibold tracking-tight">{{ t('register.closed') }}</h1>
            <p class="mt-1 text-sm text-[var(--text-secondary)]">
                {{ t('register.closedBody') }}
            </p>
            <AppButton to="/" class="mt-4">{{ t('register.backToFront') }}</AppButton>
        </template>

        <template v-else>
            <h1 class="text-xl font-semibold tracking-tight">{{ t('register.title') }}</h1>
            <p class="mt-0.5 mb-5 text-sm text-[var(--text-secondary)]">
                <!-- {{ t('register.subtitle') }} -->
            </p>

            <AlertMessage v-if="error" tone="error" class="mb-4">{{ error }}</AlertMessage>

            <form class="panel space-y-4 p-4" novalidate @submit.prevent="register">
                <FormField :label="t('auth.accountName')" :error="errors.username">
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

                <FormField :label="t('register.email')" :error="errors.email">
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

                <FormField :label="t('auth.password')" :error="errors.password">
                    <template #hint="{ id: hintId }">
                        <PasswordRequirements
                            :id="hintId"
                            :password="form.password"
                            :username="form.username"
                        />
                    </template>
                    <template #default="{ id, invalid, describedBy }">
                        <PasswordInput
                            :id="id"
                            v-model="form.password"
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

                <FormField :label="t('register.confirmPassword')">
                    <template #default="{ id }">
                        <PasswordInput
                            :id="id"
                            v-model="form.password_confirmation"
                            name="password_confirmation"
                            autocomplete="new-password"
                            :maxlength="config.password.maxLength"
                            required
                        />
                    </template>
                </FormField>

                <CaptchaField ref="captcha" v-model="form.captcha" :error="errors.captcha" />

                <AppButton type="submit" variant="primary" block :loading="submitting">
                    {{ t('register.submit') }}
                </AppButton>
            </form>

            <p class="mt-4 text-center text-sm text-[var(--text-secondary)]">
                {{ t('auth.alreadyHaveAccount') }}
                <RouterLink to="/sign-in" class="underline underline-offset-2">{{
                    t('nav.signIn')
                }}</RouterLink>
            </p>
        </template>
    </div>
</template>
