<script setup lang="ts">
import { computed } from 'vue'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import StatTile from '../components/ui/StatTile.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { useAuthStore } from '../stores/auth'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

const auth = useAuthStore()

const account = computed(() => auth.account)

const banNotice = computed(() => {
    const state = account.value?.state

    if (state?.permanently_banned) {
        return t('account.permanentlyBanned')
    }

    if (state?.temporarily_banned) {
        const until = state.ban_expires_at
            ? new Date(state.ban_expires_at).toLocaleString()
            : 'an unspecified time'

        return `This account is temporarily banned until ${until}.`
    }

    return null
})

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : t('common.never')
}
</script>

<template>
    <div v-if="account">
        <PageHeader
            :title="t('account.title')"
            :description="`Signed in as ${account.username}.`"
        />

        <AlertMessage v-if="banNotice" tone="error" :title="t('account.restricted')" class="mb-4">
            {{ banNotice }}
        </AlertMessage>

        <div class="grid gap-3 sm:grid-cols-3">
            <StatTile
                :label="t('account.credits')"
                :value="account.credits.toLocaleString()"
                context="Item shop balance"
            />
            <StatTile :label="t('account.signIns')" :value="account.login_count.toLocaleString()" />
            <StatTile :label="t('account.characterSlots')" :value="account.character_slots" />
        </div>

        <section class="panel mt-4 p-4" aria-labelledby="details">
            <h2 id="details" class="mb-3 text-sm font-semibold">{{ t('account.details') }}</h2>

            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('auth.accountName') }}
                    </dt>
                    <dd class="font-medium">{{ account.username }}</dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('account.email') }}
                    </dt>
                    <dd class="font-medium break-all">{{ account.email || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('account.group') }}
                    </dt>
                    <dd class="font-medium">
                        {{ account.group.name }}
                        <span v-if="account.group.is_staff" class="text-[var(--text-muted)]">
                            ({{ account.group.label }})
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('common.status') }}
                    </dt>
                    <dd>
                        <StatusPill
                            :state="
                                account.state.permanently_banned || account.state.temporarily_banned
                                    ? 'down'
                                    : 'up'
                            "
                            :label="account.state.label ?? 'Unknown'"
                        />
                    </dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('account.lastSignIn') }}
                    </dt>
                    <dd class="font-medium">{{ formatDate(account.last_login_at) }}</dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('account.birthdate') }}
                    </dt>
                    <dd class="font-medium">{{ account.birthdate ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('register.email') }}
                    </dt>
                    <dd class="font-medium break-all">{{ account.email }}</dd>
                </div>
            </dl>

            <div class="mt-5 border-t border-[var(--border-subtle)] pt-4">
                <div class="flex flex-wrap gap-2">
                    <AppButton to="/account/security">{{
                        t('account.changeCredentials')
                    }}</AppButton>
                    <AppButton to="/account/history/panel-logins">{{
                        t('account.viewHistory')
                    }}</AppButton>
                </div>
            </div>
        </section>
    </div>
</template>
