<script setup lang="ts">
import { computed } from 'vue'
import AlertMessage from '../components/ui/AlertMessage.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import StatTile from '../components/ui/StatTile.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()

const account = computed(() => auth.account)

const banNotice = computed(() => {
    const state = account.value?.state

    if (state?.permanently_banned) {
        return 'This account is permanently banned.'
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
    return value ? new Date(value).toLocaleString() : 'Never'
}
</script>

<template>
    <div v-if="account">
        <PageHeader title="My account" :description="`Signed in as ${account.username}.`" />

        <AlertMessage v-if="banNotice" tone="error" title="Account restricted" class="mb-4">
            {{ banNotice }}
        </AlertMessage>

        <div class="grid gap-3 sm:grid-cols-3">
            <StatTile label="Credits" :value="account.credits.toLocaleString()" context="Item shop balance" />
            <StatTile label="Sign-ins" :value="account.login_count.toLocaleString()" />
            <StatTile label="Character slots" :value="account.character_slots" />
        </div>

        <section class="panel mt-4 p-4" aria-labelledby="details">
            <h2 id="details" class="mb-3 text-sm font-semibold">Details</h2>

            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">Account name</dt>
                    <dd class="font-medium">{{ account.username }}</dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">E-mail</dt>
                    <dd class="font-medium break-all">{{ account.email || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">Group</dt>
                    <dd class="font-medium">
                        {{ account.group.name }}
                        <span v-if="account.group.is_staff" class="text-[var(--text-muted)]">
                            ({{ account.group.label }})
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">Status</dt>
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
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">Last sign-in</dt>
                    <dd class="font-medium">{{ formatDate(account.last_login_at) }}</dd>
                </div>
                <div>
                    <dt class="text-[0.8125rem] text-[var(--text-muted)]">Birthdate</dt>
                    <dd class="font-medium">{{ account.birthdate ?? '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
