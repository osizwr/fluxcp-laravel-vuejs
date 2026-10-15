<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AlertMessage from '../components/ui/AlertMessage.vue'
import AppButton from '../components/ui/AppButton.vue'
import { api, ApiError } from '../services/api'
import { useAuthStore } from '../stores/auth'
import { useTranslation } from '../i18n'

/**
 * Voting for the server, from the player's side.
 *
 * One row per listing site: what it pays, whether this account can vote on it
 * yet, and the button. The credit balance is at the top because the number
 * going up is the whole reward -- a player who has to go somewhere else to
 * find out whether it worked will assume it did not.
 *
 * Pressing Vote does not navigate. It asks the server for a URL, which mints
 * the reference the site will quote back, and opens that in a new tab, so the
 * player comes back to a page that is still here.
 *
 * The tab is opened empty *before* the request and pointed at the URL after
 * it, which looks like the long way round and is the only way that works: a
 * browser allows window.open only while it is still handling the click, and a
 * call made after an await has lost that permission -- so the obvious version
 * is silently blocked, and the player presses Vote and watches nothing
 * happen.
 */
const { t } = useTranslation()

/*
 * Only to decide whether to say where the sites are configured. An operator
 * looking at an empty page wants the filename; a player wants to be told it
 * is not set up yet and nothing more.
 */
const auth = useAuthStore()

interface Site {
    key: string
    name: string
    credits: number
    cooldown: number
    note: string | null
    ready: boolean
    next_at: string | null
}

const sites = ref<Site[]>([])
const credits = ref(0)
const loading = ref(true)
const error = ref<string | null>(null)
const starting = ref<string | null>(null)

/** Set after a vote has been opened, because the credits land later. */
const pending = ref(false)

async function load(): Promise<void> {
    try {
        const payload = await api.get<{ data: { sites: Site[]; credits: number } }>('vote')

        sites.value = payload.data.sites
        credits.value = payload.data.credits
    } catch {
        error.value = t('vote.loadFailed')
    } finally {
        loading.value = false
    }
}

async function vote(site: Site): Promise<void> {
    starting.value = site.key
    error.value = null

    // Claimed while the click is still being handled, or the browser will not
    // allow it at all. Empty for now.
    const tab = window.open('', '_blank')

    /*
     * Severed by hand, which is what `noopener` would have done -- that form
     * of window.open returns nothing, and this needs the handle to point the
     * tab somewhere once the server has answered. Without it the listing site
     * could reach back into this page through window.opener.
     */
    if (tab !== null) {
        tab.opener = null
    }

    try {
        const payload = await api.post<{ data: { url: string } }>(`vote/${site.key}`)

        /*
         * `replace`, so the blank page is not an entry in that tab's history
         * and the site's own back button behaves. If the tab never opened --
         * a blocker, or a browser that refuses them outright -- this one goes
         * instead; coming back lands on /vote, which mints nothing by itself.
         */
        if (tab !== null) {
            tab.location.replace(payload.data.url)
        } else {
            window.location.assign(payload.data.url)
        }

        pending.value = true
    } catch (failure) {
        // The blank tab is this page's mess to clear up.
        tab?.close()

        error.value = failure instanceof ApiError ? failure.message : t('common.genericError')
    } finally {
        starting.value = null
    }
}

/** Dismiss the "it is on its way" notice and ask the server again. */
function refresh(): void {
    pending.value = false
    void load()
}

/** How long until a site is ready again, as a sentence rather than a date. */
function waitFor(site: Site): string {
    if (site.next_at === null) {
        return ''
    }

    const minutes = Math.max(0, Math.ceil((Date.parse(site.next_at) - Date.now()) / 60000))

    return minutes >= 60
        ? t('vote.againInHours', { hours: Math.ceil(minutes / 60) })
        : t('vote.againInMinutes', { minutes })
}

const anyReady = computed(() => sites.value.some((site) => site.ready))

onMounted(load)
</script>

<template>
    <div class="mx-auto w-full max-w-3xl px-4 py-8">
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight">{{ t('vote.title') }}</h1>
                <p class="mt-1 text-sm text-[var(--text-secondary)]">{{ t('vote.summary') }}</p>
            </div>

            <div class="panel px-4 py-2 text-right">
                <p class="text-[0.6875rem] tracking-wide text-[var(--text-muted)] uppercase">
                    {{ t('vote.balance') }}
                </p>
                <p class="text-lg font-semibold tabular-nums">{{ credits }}</p>
            </div>
        </div>

        <AlertMessage v-if="error" tone="error" class="mt-4">{{ error }}</AlertMessage>

        <!--
            Said once a vote has been opened, because the credits arrive from
            the listing site rather than from the click: a player watching the
            number not change would otherwise conclude it is broken.
        -->
        <AlertMessage v-if="pending" tone="info" class="mt-4">
            {{ t('vote.pending') }}
            <button type="button" class="underline underline-offset-2" @click="refresh">
                {{ t('common.refresh') }}
            </button>
        </AlertMessage>

        <p v-if="loading" class="mt-6 text-sm text-[var(--text-secondary)]">
            {{ t('common.loading') }}
        </p>

        <template v-else>
            <!--
                Voting is switched on but no listing site has been registered
                yet. An ordinary state, and the order an operator does it in,
                so it is written out rather than left as an empty panel.
            -->
            <div v-if="sites.length === 0" class="panel mt-5 p-6 text-center">
                <p class="text-sm text-[var(--text-secondary)]">{{ t('vote.noSites') }}</p>
                <p
                    v-if="auth.isAdministrator"
                    class="mt-1 text-[0.8125rem] text-[var(--text-muted)]"
                >
                    {{ t('vote.noSitesOperator') }}
                </p>
            </div>

            <ul v-else class="panel mt-5 divide-y divide-[var(--border-subtle)]">
                <li
                    v-for="site in sites"
                    :key="site.key"
                    class="flex flex-wrap items-center gap-3 p-4"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ site.name }}</p>
                        <p class="text-[0.8125rem] text-[var(--text-secondary)]">
                            {{ site.ready ? t('vote.ready') : waitFor(site) }}
                        </p>
                        <p v-if="site.note" class="text-[0.8125rem] text-[var(--text-muted)]">
                            {{ site.note }}
                        </p>
                    </div>

                    <span class="text-sm font-medium text-[var(--text-secondary)] tabular-nums">
                        {{ t('vote.reward', { credits: site.credits }) }}
                    </span>

                    <AppButton
                        variant="primary"
                        size="sm"
                        :disabled="!site.ready"
                        :loading="starting === site.key"
                        @click="vote(site)"
                    >
                        {{ site.ready ? t('vote.vote') : t('vote.waiting') }}
                    </AppButton>
                </li>
            </ul>

            <p v-if="!anyReady && sites.length > 0" class="mt-3 text-sm text-[var(--text-muted)]">
                {{ t('vote.allWaiting') }}
            </p>
        </template>
    </div>
</template>
