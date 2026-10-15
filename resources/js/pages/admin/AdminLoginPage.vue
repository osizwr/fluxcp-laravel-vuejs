<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AlertMessage from '../../components/ui/AlertMessage.vue'
import AppButton from '../../components/ui/AppButton.vue'
import FormField from '../../components/ui/FormField.vue'
import PasswordInput from '../../components/ui/PasswordInput.vue'
import { ApiError } from '../../services/api'
import { useAuthStore } from '../../stores/auth'
import { useTranslation } from '../../i18n'

/**
 * Signing in to the dashboard.
 *
 * It looks like the player form and behaves unlike it in two ways, both
 * deliberate. There is no "create an account" and no "forgot password": an
 * administrator who has lost theirs has a database, and offering a reset here
 * would be offering it to whoever found the page. And there is no "keep me
 * signed in", because the server does not honour one for staff.
 *
 * Every failure renders the same sentence. The endpoint cannot tell us which
 * of wrong password, unknown account or "not an administrator" happened, by
 * design -- see AdminSessionController.
 */
const { t } = useTranslation()

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ username: '', password: '' })
const errors = reactive<Record<string, string>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

async function submit(): Promise<void> {
    submitting.value = true
    generalError.value = null
    Object.keys(errors).forEach((key) => delete errors[key])

    try {
        await auth.adminLogin({ ...form })

        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null

        /*
         * Only ever back into the dashboard. The player form restricts its
         * redirect to a local path; this one restricts it further, because a
         * link that signs somebody in as an administrator and then drops them
         * on an arbitrary page is worth narrowing.
         */
        await router.push(redirect?.startsWith('/admin') ? redirect : '/admin')
    } catch (error) {
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
    <div class="mx-auto max-w-sm py-6">
        <h1 class="text-xl font-semibold tracking-tight">{{ t('admin.signInTitle') }}</h1>
        <p class="mt-0.5 mb-5 text-sm text-[var(--text-secondary)]">
            {{ t('admin.signInSubtitle') }}
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

            <AppButton type="submit" variant="primary" block :loading="submitting">
                {{ t('nav.signIn') }}
            </AppButton>
        </form>

        <p class="mt-4 text-center text-[0.8125rem] text-[var(--text-muted)]">
            {{ t('admin.signInNote') }}
        </p>
    </div>
</template>
