<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppButton from '../components/ui/AppButton.vue'
import DataTable, { type Column } from '../components/ui/DataTable.vue'
import PageHeader from '../components/ui/PageHeader.vue'
import { api } from '../services/api'

/**
 * The public ladders.
 *
 * Characters belonging to banned or staff accounts are excluded by the server,
 * so nothing here filters them: the client must not be the thing deciding who
 * appears.
 *
 * Eight ladders of three shapes — some rank characters, one ranks homunculi
 * and one ranks guilds — so the columns and the row renderer are chosen per
 * ladder rather than assuming every row has a character on it.
 */
const route = useRoute()

/** A row from any ladder; which fields are present depends on the ladder. */
type LadderRow = Record<string, unknown> & { rank: number }

interface LadderDefinition {
    slug: string
    label: string
    /** Heading shown above the table. */
    description: string
    columns: Column[]
}

const LADDERS: LadderDefinition[] = [
    {
        slug: 'level',
        label: 'Level',
        description: 'The highest-level characters on this server.',
        columns: [
            { key: 'rank', label: '#', numeric: true },
            { key: 'name', label: 'Character' },
            { key: 'job', label: 'Job', secondary: true },
            { key: 'level', label: 'Level', numeric: true },
            { key: 'guild', label: 'Guild', secondary: true },
        ],
    },
    {
        slug: 'zeny',
        label: 'Zeny',
        description: 'The wealthiest characters who have not opted out.',
        columns: [
            { key: 'rank', label: '#', numeric: true },
            { key: 'name', label: 'Character' },
            { key: 'job', label: 'Job', secondary: true },
            { key: 'zeny', label: 'Zeny', numeric: true },
            { key: 'guild', label: 'Guild', secondary: true },
        ],
    },
    {
        slug: 'alchemist',
        label: 'Alchemist',
        description: 'Ranked by fame earned brewing.',
        columns: [
            { key: 'rank', label: '#', numeric: true },
            { key: 'name', label: 'Character' },
            { key: 'job', label: 'Job', secondary: true },
            { key: 'fame', label: 'Fame', numeric: true },
            { key: 'guild', label: 'Guild', secondary: true },
        ],
    },
    {
        slug: 'blacksmith',
        label: 'Blacksmith',
        description: 'Ranked by fame earned forging.',
        columns: [
            { key: 'rank', label: '#', numeric: true },
            { key: 'name', label: 'Character' },
            { key: 'job', label: 'Job', secondary: true },
            { key: 'fame', label: 'Fame', numeric: true },
            { key: 'guild', label: 'Guild', secondary: true },
        ],
    },
    {
        slug: 'deaths',
        label: 'Deaths',
        description: 'Characters who have died the most.',
        columns: [
            { key: 'rank', label: '#', numeric: true },
            { key: 'name', label: 'Character' },
            { key: 'job', label: 'Job', secondary: true },
            { key: 'deaths', label: 'Deaths', numeric: true },
            { key: 'guild', label: 'Guild', secondary: true },
        ],
    },
    {
        slug: 'mvp',
        label: 'MVP',
        description: 'Who has felled the most bosses.',
        columns: [
            { key: 'rank', label: '#', numeric: true },
            { key: 'name', label: 'Character' },
            { key: 'monster', label: 'Monster' },
            { key: 'kills', label: 'Kills', numeric: true },
            { key: 'guild', label: 'Guild', secondary: true },
        ],
    },
    {
        slug: 'homunculus',
        label: 'Homunculus',
        description: 'The strongest homunculi and who raised them.',
        columns: [
            { key: 'rank', label: '#', numeric: true },
            { key: 'homunculus', label: 'Homunculus' },
            { key: 'homunculusClass', label: 'Type', secondary: true },
            { key: 'homunculusLevel', label: 'Level', numeric: true },
            { key: 'owner', label: 'Owner', secondary: true },
        ],
    },
    {
        slug: 'guilds',
        label: 'Guilds',
        description: 'Ranked by level, castles held, and experience.',
        columns: [
            { key: 'rank', label: '#', numeric: true },
            { key: 'guildName', label: 'Guild' },
            { key: 'guildLevel', label: 'Level', numeric: true },
            { key: 'members', label: 'Members', numeric: true },
            { key: 'castles', label: 'Castles', numeric: true, secondary: true },
        ],
    },
]

const entries = ref<LadderRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const ladder = computed<LadderDefinition>(
    () =>
        LADDERS.find((candidate) => candidate.slug === route.params.ladder) ??
        (LADDERS[0] as LadderDefinition),
)

/** Reads a nested value without assuming the shape of a row this ladder lacks. */
function at(row: LadderRow, path: string): unknown {
    return path
        .split('.')
        .reduce<unknown>(
            (value, key) =>
                value !== null && typeof value === 'object'
                    ? (value as Record<string, unknown>)[key]
                    : undefined,
            row,
        )
}

function text(row: LadderRow, path: string, fallback = '—'): string {
    const value = at(row, path)

    return value === null || value === undefined || value === '' ? fallback : String(value)
}

function number(row: LadderRow, path: string): string {
    const value = at(row, path)

    return typeof value === 'number' ? value.toLocaleString() : '—'
}

async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
        const response = await api.get<{ data: LadderRow[] }>(`rankings/${ladder.value.slug}`)
        entries.value = response.data
    } catch {
        error.value = 'The ranking could not be loaded.'
        entries.value = []
    } finally {
        loading.value = false
    }
}

watch(ladder, load, { immediate: true })
</script>

<template>
    <div>
        <PageHeader title="Rankings" :description="ladder.description">
            <template #actions>
                <nav aria-label="Ladder" class="flex flex-wrap gap-1">
                    <AppButton
                        v-for="option in LADDERS"
                        :key="option.slug"
                        size="sm"
                        :variant="ladder.slug === option.slug ? 'primary' : 'secondary'"
                        :to="`/rankings/${option.slug}`"
                    >
                        {{ option.label }}
                    </AppButton>
                </nav>
            </template>
        </PageHeader>

        <DataTable
            class="mt-4"
            :columns="ladder.columns"
            :rows="entries"
            :row-key="(row: LadderRow) => row.rank"
            :loading="loading"
            :error="error"
            :caption="`${ladder.label} ranking`"
            empty-title="Nothing on this ladder yet"
            empty-description="Nobody qualifies for it at the moment."
        >
            <template #retry><AppButton size="sm" @click="load">Try again</AppButton></template>

            <template #cell:rank="{ row }">{{ row.rank }}</template>

            <template #cell:name="{ row }">
                {{ text(row, 'character.name') }}
            </template>

            <template #cell:job="{ row }">{{ text(row, 'character.job_name') }}</template>
            <template #cell:level="{ row }">{{ number(row, 'character.base_level') }}</template>
            <template #cell:zeny="{ row }">{{ number(row, 'character.zeny') }}</template>
            <template #cell:fame="{ row }">{{ number(row, 'fame') }}</template>
            <template #cell:deaths="{ row }">{{ number(row, 'deaths') }}</template>
            <template #cell:kills="{ row }">{{ number(row, 'kills') }}</template>

            <template #cell:monster="{ row }">
                <RouterLink
                    v-if="at(row, 'monster.id')"
                    :to="`/monsters/${at(row, 'monster.id')}`"
                    class="underline underline-offset-2"
                >
                    {{ text(row, 'monster.name') }}
                </RouterLink>
                <template v-else>—</template>
            </template>

            <template #cell:guild="{ row }">{{ text(row, 'guild.name') }}</template>

            <template #cell:homunculus="{ row }">{{ text(row, 'homunculus.name') }}</template>
            <template #cell:homunculusClass="{ row }">
                {{ text(row, 'homunculus.class_name') }}
            </template>
            <template #cell:homunculusLevel="{ row }">
                {{ number(row, 'homunculus.level') }}
            </template>
            <template #cell:owner="{ row }">{{ text(row, 'owner.name') }}</template>

            <template #cell:guildName="{ row }">{{ text(row, 'guild.name') }}</template>
            <template #cell:guildLevel="{ row }">{{ number(row, 'guild.level') }}</template>
            <template #cell:members="{ row }">
                {{ number(row, 'guild.members') }} / {{ number(row, 'guild.max_members') }}
            </template>
            <template #cell:castles="{ row }">{{ number(row, 'guild.castles') }}</template>
        </DataTable>
    </div>
</template>
