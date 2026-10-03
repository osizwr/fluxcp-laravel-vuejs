<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import StateBlock from '../components/ui/StateBlock.vue'
import { api, ApiError } from '../services/api'
import { useAuthStore } from '../stores/auth'

/**
 * Confirming a new e-mail address.
 *
 * Requires a session, and the request has to belong to the signed-in account.
 * A token read out of somebody's mailbox is therefore not enough on its own to
 * move their address -- which is the legacy behaviour, and worth keeping.
 */
const route = useRoute()
const auth = useAuthStore()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))

const state = ref<'working' | 'confirmed' | 'failed'>('working')
const message = ref('')

onMounted(async () => {
    if (token.value === '') {
        state.value = 'failed'
        message.value = 'This confirmation link is incomplete.'

        return
    }

    try {
        const response = await api.post<{ message: string; email: string }>(
            'account/email/confirm',
            { token: token.value },
        )

        state.value = 'confirmed'
        message.value = response.message

        await auth.refresh()
    } catch (caught) {
        state.value = 'failed'
        message.value =
            caught instanceof ApiError
                ? caught.message
                : 'Something went wrong. Please try again.'
    }
})
</script>

<template>
    <div class="mx-auto max-w-sm py-6">
        <h1 class="text-xl font-semibold tracking-tight">E-mail confirmation</h1>

        <StateBlock
            v-if="state === 'working'"
            class="mt-4"
            variant="loading"
            title="Confirming your address…"
        />

        <template v-else-if="state === 'confirmed'">
            <AlertMessage tone="success" class="mt-4">{{ message }}</AlertMessage>
            <AppButton to="/account" variant="primary" class="mt-4">Back to my account</AppButton>
        </template>

        <template v-else>
            <AlertMessage tone="error" class="mt-4">{{ message }}</AlertMessage>

            <p class="mt-4 text-sm text-[var(--text-secondary)]">
                If the link has expired, request the change again from your security settings.
            </p>

            <AppButton to="/account/security" variant="primary" class="mt-4">
                Security settings
            </AppButton>
        </template>
    </div>
</template>
