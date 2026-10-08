<script setup lang="ts">
import { computed } from 'vue'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import StateBlock from '../components/ui/StateBlock.vue'
import StatTile from '../components/ui/StatTile.vue'
import StatusPill from '../components/ui/StatusPill.vue'
import { useServerStore } from '../stores/server'
import { useTranslation } from '../i18n'

const { t } = useTranslation()

/**
 * Server status.
 *
 * Every figure here is measured rather than estimated: reachability from a real
 * TCP connection to each emulator process, and the player count from rAthena's
 * own char.online column.
 */
const servers = useServerStore()

const totalPeak = computed(() => {
    const peaks = servers.groups
        .flatMap((group) => group.servers)
        .map((server) => server.players_peak)
        .filter((peak): peak is number => peak !== null)

    return peaks.length > 0 ? Math.max(...peaks) : null
})

const measuredAt = computed(() => {
    if (servers.measuredAt === null) {
        return undefined
    }

    return `Measured ${new Date(servers.measuredAt).toLocaleTimeString()}`
})

function processState(up: boolean): 'up' | 'down' {
    return up ? 'up' : 'down'
}
</script>

<template>
    <div>
        <PageHeader :title="t('server.heading')" description="Live status of the game servers.">
            <template #actions>
                <AppButton size="sm" :loading="servers.loading" @click="servers.load()">
                    {{ t('common.refresh') }}
                </AppButton>
            </template>
        </PageHeader>

        <AlertMessage v-if="servers.woeInProgress" tone="warning" :title="t('server.woe')">
            {{ t('server.woeSomePages') }}
        </AlertMessage>

        <div v-if="servers.error" class="mt-4">
            <AlertMessage tone="error" :title="t('server.unavailable')">
                {{ servers.error }}
            </AlertMessage>
        </div>

        <StateBlock
            v-else-if="servers.loading && servers.groups.length === 0"
            variant="loading"
            :title="t('common.checkingServers')"
        />

        <template v-else>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <StatTile
                    :label="t('server.playersOnline')"
                    :value="servers.playersOnline.toLocaleString()"
                    :context="measuredAt"
                />
                <StatTile
                    :label="t('common.status')"
                    :value="servers.anyServerUp ? 'Online' : 'Offline'"
                    :context="servers.anyServerUp ? 'Accepting connections' : 'Not reachable'"
                />
                <StatTile
                    v-if="totalPeak !== null"
                    :label="t('server.peakPlayers')"
                    :value="totalPeak.toLocaleString()"
                    context="Highest recorded"
                />
            </div>

            <section
                v-for="group in servers.groups"
                :key="group.key"
                class="mt-6"
                :aria-labelledby="`group-${group.key}`"
            >
                <h2
                    :id="`group-${group.key}`"
                    class="mb-2 text-sm font-semibold tracking-wide text-[var(--text-secondary)] uppercase"
                >
                    {{ group.name }}
                </h2>

                <div class="grid gap-3 md:grid-cols-2">
                    <article v-for="server in group.servers" :key="server.key" class="panel p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold">{{ server.name }}</h3>
                                <p class="tabular text-sm text-[var(--text-secondary)]">
                                    {{ server.players_online.toLocaleString() }} online
                                    <template v-if="server.players_peak !== null">
                                        · peak {{ server.players_peak.toLocaleString() }}
                                    </template>
                                </p>
                            </div>

                            <StatusPill
                                :state="processState(server.playable)"
                                :label="server.playable ? 'Playable' : 'Unavailable'"
                            />
                        </div>

                        <!--
                            All three processes are shown rather than one rolled
                            up state, because which one is down tells a player
                            whether to wait or to report it.
                        -->
                        <dl
                            class="mt-3 grid grid-cols-3 gap-2 border-t border-[var(--border-subtle)] pt-3"
                        >
                            <div>
                                <dt class="text-[0.75rem] text-[var(--text-muted)]">
                                    {{ t('server.login') }}
                                </dt>
                                <dd class="mt-0.5">
                                    <StatusPill
                                        bare
                                        :state="processState(server.login_server_up)"
                                        :label="server.login_server_up ? 'Up' : 'Down'"
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[0.75rem] text-[var(--text-muted)]">
                                    {{ t('common.character') }}
                                </dt>
                                <dd class="mt-0.5">
                                    <StatusPill
                                        bare
                                        :state="processState(server.char_server_up)"
                                        :label="server.char_server_up ? 'Up' : 'Down'"
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[0.75rem] text-[var(--text-muted)]">
                                    {{ t('server.map') }}
                                </dt>
                                <dd class="mt-0.5">
                                    <StatusPill
                                        bare
                                        :state="processState(server.map_server_up)"
                                        :label="server.map_server_up ? 'Up' : 'Down'"
                                    />
                                </dd>
                            </div>
                        </dl>

                        <p
                            v-if="server.woe_active"
                            class="mt-3 text-[0.8125rem] font-medium text-[var(--color-warn)]"
                        >
                            {{ t('server.woe') }}
                        </p>
                    </article>
                </div>
            </section>
        </template>
    </div>
</template>
