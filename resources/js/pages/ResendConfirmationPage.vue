<script setup lang="ts">
import { reactive, ref } from 'vue'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import FormField from '../components/ui/FormField.vue'
import { useFormSubmit } from '../composables/useFormSubmit'
import { api } from '../services/api'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * Asking for another account confirmation e-mail.
 *
 * Both the account name and the address on it are required, so this cannot be
 * used to mail a confirmation link to an address somebody merely typed in.
 */
const { errors, error, submitting, submit } = useFormSubmit()

const form = reactive({ username: '', email: '' })
const sentMessage = ref<string | null>(null)

async function resend(): Promise<void> {
    await submit(async () => {
        const response = await api.post<{ message: string }>('auth/confirm/resend', { ...form })

        sentMessage.value = response.message
    })
}
</script>

<template>
    <div class="mx-auto max-w-sm py-6">
        <template v-if="sentMessage">
            <h1 class="text-xl font-semibold tracking-tight">{{ t('register.checkEmail') }}</h1>

            <AlertMessage tone="success" class="mt-4">{{ sentMessage }}</AlertMessage>

            <p class="mt-4 text-sm text-[var(--text-secondary)]">
                {{ t('confirm.anyEarlierStops') }}
            </p>

            <AppButton to="/sign-in" class="mt-4">{{ t('auth.backToSignIn') }}</AppButton>
        </template>

        <template v-else>
            <h1 class="text-xl font-semibold tracking-tight">{{ t('confirm.resendTitle') }}</h1>
            <p class="mt-0.5 mb-5 text-sm text-[var(--text-secondary)]">
                If your account is still waiting to be confirmed, we will send a new link.
            </p>

            <AlertMessage v-if="error" tone="error" class="mb-4">{{ error }}</AlertMessage>

            <form class="panel space-y-4 p-4" novalidate @submit.prevent="resend">
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
                    {{ t('confirm.sendNewLink') }}
                </AppButton>
            </form>
        </template>
    </div>
</template>
