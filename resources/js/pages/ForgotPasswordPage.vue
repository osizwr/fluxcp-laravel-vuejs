<script setup lang="ts">
import { reactive, ref } from 'vue'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import FormField from '../components/ui/FormField.vue'
import { useAccounts } from '../composables/useAccounts'
import { useFormSubmit } from '../composables/useFormSubmit'
import { api } from '../services/api'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * Asking for a password reset link.
 *
 * Note that this page cannot tell you whether an account exists, because the
 * endpoint answers identically either way. That is the point: a form that says
 * "no such account" is a form that tells you which accounts exist.
 */
const { passwordResetEnabled } = useAccounts()
const { errors, error, submitting, submit } = useFormSubmit()

const form = reactive({ username: '', email: '' })
const sentMessage = ref<string | null>(null)

async function request(): Promise<void> {
    await submit(async () => {
        const response = await api.post<{ message: string }>('auth/password/forgot', { ...form })

        sentMessage.value = response.message
    })
}
</script>

<template>
    <div class="mx-auto max-w-sm py-6">
        <template v-if="!passwordResetEnabled">
            <h1 class="text-xl font-semibold tracking-tight">{{ t('forgot.unavailable') }}</h1>
            <p class="mt-1 text-sm text-[var(--text-secondary)]">
                {{ t('forgot.contactAdmin') }}
            </p>
            <AppButton to="/sign-in" class="mt-4">{{ t('auth.backToSignIn') }}</AppButton>
        </template>

        <template v-else-if="sentMessage">
            <h1 class="text-xl font-semibold tracking-tight">{{ t('register.checkEmail') }}</h1>

            <AlertMessage tone="success" class="mt-4">{{ sentMessage }}</AlertMessage>

            <p class="mt-4 text-sm text-[var(--text-secondary)]">
                The link can be used once, and expires. Your current password keeps working until
                you choose a new one.
            </p>

            <AppButton to="/sign-in" class="mt-4">{{ t('auth.backToSignIn') }}</AppButton>
        </template>

        <template v-else>
            <h1 class="text-xl font-semibold tracking-tight">{{ t('auth.forgotPassword') }}</h1>
            <p class="mt-0.5 mb-5 text-sm text-[var(--text-secondary)]">
                Give the account name and the e-mail address on it, and we will send a link to
                choose a new password.
            </p>

            <AlertMessage v-if="error" tone="error" class="mb-4">{{ error }}</AlertMessage>

            <form class="panel space-y-4 p-4" novalidate @submit.prevent="request">
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

                <AppButton type="submit" variant="primary" block :loading="submitting">
                    {{ t('forgot.sendResetLink') }}
                </AppButton>
            </form>

            <p class="mt-4 text-center text-sm text-[var(--text-secondary)]">
                <RouterLink to="/sign-in" class="underline underline-offset-2">
                    {{ t('auth.backToSignIn') }}
                </RouterLink>
            </p>
        </template>
    </div>
</template>
