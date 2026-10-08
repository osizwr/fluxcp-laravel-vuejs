<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import StateBlock from '../components/ui/StateBlock.vue'
import { api, ApiError } from '../services/api'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * Following an account confirmation link.
 *
 * The link is a GET that the browser follows, but confirming is a change, so
 * the actual confirmation is a POST made from here. That keeps the state
 * change off the GET -- where a mail client prefetching the link, or a
 * crawler following it, would otherwise trigger it.
 */
const route = useRoute()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const server = computed(() => (typeof route.query.server === 'string' ? route.query.server : ''))

const state = ref<'working' | 'confirmed' | 'failed'>('working')
const message = ref('')

onMounted(async () => {
    if (token.value === '') {
        state.value = 'failed'
        message.value = t('confirm.incompleteLink')

        return
    }

    try {
        const response = await api.post<{ message: string }>('auth/confirm', {
            token: token.value,
            server: server.value,
        })

        state.value = 'confirmed'
        message.value = response.message
    } catch (caught) {
        state.value = 'failed'
        message.value = caught instanceof ApiError ? caught.message : t('common.genericError')
    }
})
</script>

<template>
    <div class="mx-auto max-w-sm py-6">
        <h1 class="text-xl font-semibold tracking-tight">{{ t('confirm.accountTitle') }}</h1>

        <StateBlock
            v-if="state === 'working'"
            class="mt-4"
            variant="loading"
            :title="t('confirm.confirmingAccount')"
        />

        <template v-else-if="state === 'confirmed'">
            <AlertMessage tone="success" class="mt-4">{{ message }}</AlertMessage>
            <AppButton to="/sign-in" variant="primary" class="mt-4">{{
                t('nav.signIn')
            }}</AppButton>
        </template>

        <template v-else>
            <AlertMessage tone="error" class="mt-4">{{ message }}</AlertMessage>

            <p class="mt-4 text-sm text-[var(--text-secondary)]">
                {{ t('confirm.expiredAsk') }}
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <AppButton to="/resend-confirmation" variant="primary">
                    {{ t('register.sendAnother') }}
                </AppButton>
                <AppButton to="/sign-in">{{ t('auth.goToSignIn') }}</AppButton>
            </div>
        </template>
    </div>
</template>
