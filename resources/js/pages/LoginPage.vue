<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import FormField from '../components/ui/FormField.vue'
import { ApiError } from '../services/api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({ username: '', password: '', remember: false })
const errors = reactive<Record<string, string>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

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
        if (error instanceof ApiError && (error.isValidation || error.isRateLimited)) {
            for (const [field, messages] of Object.entries(error.errors)) {
                errors[field] = messages[0]
            }

            if (Object.keys(error.errors).length === 0) {
                generalError.value = error.message
            }
        } else {
            generalError.value = 'Something went wrong. Please try again.'
        }
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <div class="mx-auto max-w-sm py-6">
        <h1 class="text-xl font-semibold tracking-tight">Sign in</h1>
        <p class="mt-0.5 mb-5 text-sm text-[var(--text-secondary)]">
            Use your game account to sign in.
        </p>

        <AlertMessage v-if="generalError" tone="error" class="mb-4">
            {{ generalError }}
        </AlertMessage>

        <form class="panel space-y-4 p-4" novalidate @submit.prevent="submit">
            <FormField label="Account name" :error="errors.username">
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

            <FormField label="Password" :error="errors.password">
                <template #default="{ id, invalid, describedBy }">
                    <input
                        :id="id"
                        v-model="form.password"
                        class="field-input"
                        type="password"
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
                Keep me signed in
            </label>

            <AppButton type="submit" variant="primary" block :loading="submitting">
                Sign in
            </AppButton>
        </form>
    </div>
</template>
