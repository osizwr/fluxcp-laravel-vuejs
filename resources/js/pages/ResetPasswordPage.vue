<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import FormField from '../components/ui/FormField.vue'
import { useAccounts } from '../composables/useAccounts'
import { useFormSubmit } from '../composables/useFormSubmit'
import { api } from '../services/api'

/**
 * Choosing a new password from a reset link.
 *
 * The password is chosen here rather than generated and e-mailed, which is
 * what the legacy panel did. See docs/MIGRATION_DECISIONS.md (D16).
 */
const route = useRoute()

const { passwordHint, config } = useAccounts()
const { errors, error, submitting, submit } = useFormSubmit()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const server = computed(() => (typeof route.query.server === 'string' ? route.query.server : ''))

const form = reactive({ password: '', password_confirmation: '' })
const done = ref(false)

async function reset(): Promise<void> {
    await submit(async () => {
        await api.post('auth/password/reset', {
            token: token.value,
            server: server.value,
            ...form,
        })

        done.value = true
    })
}
</script>

<template>
    <div class="mx-auto max-w-sm py-6">
        <template v-if="done">
            <h1 class="text-xl font-semibold tracking-tight">Password changed</h1>

            <AlertMessage tone="success" class="mt-4">
                You can sign in with your new password now, in the game and here.
            </AlertMessage>

            <AppButton to="/sign-in" variant="primary" class="mt-4">Sign in</AppButton>
        </template>

        <template v-else-if="token === ''">
            <h1 class="text-xl font-semibold tracking-tight">This link is incomplete</h1>
            <p class="mt-1 text-sm text-[var(--text-secondary)]">
                Open the link from your e-mail exactly as it was sent, or ask for a new one.
            </p>
            <AppButton to="/forgot-password" variant="primary" class="mt-4">
                Request a new link
            </AppButton>
        </template>

        <template v-else>
            <h1 class="text-xl font-semibold tracking-tight">Choose a new password</h1>
            <p class="mt-0.5 mb-5 text-sm text-[var(--text-secondary)]">
                This replaces your password for both the game and this website.
            </p>

            <AlertMessage v-if="error" tone="error" class="mb-4">
                {{ error }}
                <RouterLink to="/forgot-password" class="underline underline-offset-2">
                    Request a new link
                </RouterLink>
            </AlertMessage>

            <form class="panel space-y-4 p-4" novalidate @submit.prevent="reset">
                <FormField label="New password" :error="errors.password" :hint="passwordHint">
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

                <FormField label="Confirm new password">
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

                <AppButton type="submit" variant="primary" block :loading="submitting">
                    Change my password
                </AppButton>
            </form>
        </template>
    </div>
</template>
