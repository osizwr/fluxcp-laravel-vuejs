<script setup lang="ts">
import { reactive, ref } from 'vue'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import FormField from '../components/ui/FormField.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import { useAccounts } from '../composables/useAccounts'
import { useFormSubmit } from '../composables/useFormSubmit'
import { api } from '../services/api'
import { useAuthStore } from '../stores/auth'

/**
 * Changing the signed-in account's password and e-mail address.
 *
 * Two independent forms, each with its own submission state, so a failure in
 * one does not clear or disable the other.
 *
 * Both ask for the current password. For the e-mail form that is stricter than
 * the legacy panel, which asked for nothing: the address is what password
 * reset trusts, so a stolen session cookie must not be enough to move it.
 */
const auth = useAuthStore()
const { passwordHint, config } = useAccounts()

const password = useFormSubmit()
const passwordForm = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
})

/** Null until a change has happened, then what the server reported about it. */
const passwordResult = ref<{ message: string; otherSessionsRevoked: boolean } | null>(null)

async function changePassword(): Promise<void> {
    const changed = await password.submit(async () => {
        const response = await api.put<{
            message: string
            other_sessions_revoked: boolean
        }>('account/password', { ...passwordForm })

        passwordResult.value = {
            message: response.message,
            otherSessionsRevoked: response.other_sessions_revoked,
        }
    })

    if (changed) {
        passwordForm.current_password = ''
        passwordForm.password = ''
        passwordForm.password_confirmation = ''
    }
}

const email = useFormSubmit()
const emailForm = reactive({
    current_password: '',
    email: '',
    email_confirmation: '',
})

const emailResult = ref<{ message: string; requiresConfirmation: boolean } | null>(null)

async function changeEmail(): Promise<void> {
    const changed = await email.submit(async () => {
        const response = await api.put<{
            message: string
            email: string
            requires_confirmation: boolean
        }>('account/email', { ...emailForm })

        emailResult.value = {
            message: response.message,
            requiresConfirmation: response.requires_confirmation,
        }

        // Only refresh when the address actually moved. With confirmation on
        // it has not, and reloading would make the form look like it failed.
        if (!response.requires_confirmation) {
            await auth.refresh()
        }
    })

    if (changed) {
        emailForm.current_password = ''
        emailForm.email = ''
        emailForm.email_confirmation = ''
    }
}
</script>

<template>
    <div class="py-6">
        <PageHeader title="Security" description="Your password and e-mail address." />

        <div class="mt-5 grid gap-5 lg:grid-cols-2">
            <!-- Password -->
            <section class="panel p-4">
                <h2 class="text-base font-semibold tracking-tight">Change password</h2>
                <p class="mt-0.5 mb-4 text-sm text-[var(--text-secondary)]">
                    This changes your password for the game as well as this website.
                </p>

                <AlertMessage v-if="passwordResult" tone="success" class="mb-4">
                    {{ passwordResult.message }}
                    <template v-if="passwordResult.otherSessionsRevoked">
                        Anyone signed in elsewhere has been signed out.
                    </template>
                </AlertMessage>

                <AlertMessage v-if="password.error.value" tone="error" class="mb-4">
                    {{ password.error.value }}
                </AlertMessage>

                <form class="space-y-4" novalidate @submit.prevent="changePassword">
                    <FormField label="Current password" :error="password.errors.current_password">
                        <template #default="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="passwordForm.current_password"
                                class="field-input"
                                type="password"
                                name="current_password"
                                autocomplete="current-password"
                                required
                                :aria-invalid="invalid || undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="New password"
                        :error="password.errors.password"
                        :hint="passwordHint"
                    >
                        <template #default="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="passwordForm.password"
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

                    <FormField label="Confirm new password">
                        <template #default="{ id }">
                            <input
                                :id="id"
                                v-model="passwordForm.password_confirmation"
                                class="field-input"
                                type="password"
                                name="password_confirmation"
                                autocomplete="new-password"
                                :maxlength="config.password.maxLength"
                                required
                            />
                        </template>
                    </FormField>

                    <AppButton
                        type="submit"
                        variant="primary"
                        :loading="password.submitting.value"
                    >
                        Change password
                    </AppButton>
                </form>
            </section>

            <!-- E-mail -->
            <section class="panel p-4">
                <h2 class="text-base font-semibold tracking-tight">Change e-mail address</h2>
                <p class="mt-0.5 mb-4 text-sm text-[var(--text-secondary)]">
                    Currently
                    <strong class="text-[var(--text-primary)]">
                        {{ auth.account?.email }}
                    </strong>
                    <template v-if="config.emailChangeRequiresConfirmation">
                        . A new address has to be confirmed before it replaces this one.
                    </template>
                </p>

                <AlertMessage
                    v-if="emailResult"
                    :tone="emailResult.requiresConfirmation ? 'info' : 'success'"
                    class="mb-4"
                >
                    {{ emailResult.message }}
                </AlertMessage>

                <AlertMessage v-if="email.error.value" tone="error" class="mb-4">
                    {{ email.error.value }}
                </AlertMessage>

                <form class="space-y-4" novalidate @submit.prevent="changeEmail">
                    <FormField label="Current password" :error="email.errors.current_password">
                        <template #default="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="emailForm.current_password"
                                class="field-input"
                                type="password"
                                name="current_password"
                                autocomplete="current-password"
                                required
                                :aria-invalid="invalid || undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </FormField>

                    <FormField label="New e-mail address" :error="email.errors.email">
                        <template #default="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model.trim="emailForm.email"
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

                    <FormField label="Confirm new e-mail address">
                        <template #default="{ id }">
                            <input
                                :id="id"
                                v-model.trim="emailForm.email_confirmation"
                                class="field-input"
                                type="email"
                                name="email_confirmation"
                                autocomplete="email"
                                maxlength="39"
                                required
                            />
                        </template>
                    </FormField>

                    <AppButton type="submit" variant="primary" :loading="email.submitting.value">
                        Change e-mail address
                    </AppButton>
                </form>
            </section>
        </div>
    </div>
</template>
