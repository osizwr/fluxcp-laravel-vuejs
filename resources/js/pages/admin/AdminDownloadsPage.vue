<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AlertMessage from '../../components/ui/AlertMessage.vue'
import AppButton from '../../components/ui/AppButton.vue'
import { api, ApiError } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * The download page, edited.
 *
 * The whole section is loaded, edited and saved as one document rather than
 * field by field. Two reasons: the configuration format has no stable identity
 * for a package -- they are a list, and reordering is an edit -- so there is
 * nothing to address a PATCH at; and an operator swapping a dead mirror
 * usually fixes two others while they are in here, which is one save rather
 * than three.
 *
 * Validation errors come back keyed by path -- `clients.0.mirrors.1.url` --
 * and are shown against the field they name. Anything the server rejected that
 * this form cannot place still surfaces in the banner, so a rejected save is
 * never silent.
 */
const { t } = useTranslation()

interface Mirror {
    label: string
    url: string
}

interface DownloadClient {
    name: string
    platform: string | null
    badge: string | null
    version: string | null
    size: string | null
    updated: string | null
    description: string
    mirrors: Mirror[]
    notes: string[]
}

interface Meta {
    platforms: string[]
    overridden: boolean
    updated_at: string | null
    updated_by: number | null
}

const notice = ref<string>('')
const clients = ref<DownloadClient[]>([])
const meta = ref<Meta>({ platforms: [], overridden: false, updated_at: null, updated_by: null })

const loading = ref(true)
const saving = ref(false)
const resetting = ref(false)
const loadError = ref<string | null>(null)
const generalError = ref<string | null>(null)
const saved = ref(false)

/** Server-side validation errors, keyed by the path the server used. */
const errors = ref<Record<string, string[]>>({})

function errorFor(path: string): string | undefined {
    return errors.value[path]?.[0]
}

/**
 * The messages that could not be placed against a field.
 *
 * A rule this form has no input for -- `clients` exceeding its maximum, say --
 * would otherwise be rejected with nothing on screen to show for it.
 */
function unplacedErrors(): string[] {
    const placed = new Set<string>()

    placed.add('notice')

    clients.value.forEach((client, index) => {
        placed.add(`clients.${index}.name`)
        placed.add(`clients.${index}.platform`)
        placed.add(`clients.${index}.badge`)
        placed.add(`clients.${index}.version`)
        placed.add(`clients.${index}.size`)
        placed.add(`clients.${index}.updated`)
        placed.add(`clients.${index}.description`)

        client.mirrors.forEach((_, mirror) => {
            placed.add(`clients.${index}.mirrors.${mirror}.label`)
            placed.add(`clients.${index}.mirrors.${mirror}.url`)
        })

        client.notes.forEach((_, note) => {
            placed.add(`clients.${index}.notes.${note}`)
        })
    })

    return Object.entries(errors.value)
        .filter(([path]) => !placed.has(path))
        .map(([, messages]) => messages[0])
}

interface Payload {
    data: { notice: string | null; clients: DownloadClient[] }
    meta: Meta
}

function apply(payload: Payload): void {
    notice.value = payload.data.notice ?? ''
    clients.value = payload.data.clients.map((client) => ({
        ...client,
        mirrors: client.mirrors.map((mirror) => ({ ...mirror })),
        notes: [...client.notes],
    }))
    meta.value = payload.meta
}

async function load(): Promise<void> {
    loading.value = true
    loadError.value = null

    try {
        apply(await api.get<Payload>('admin/settings/downloads'))
    } catch {
        loadError.value = t('admin.downloads.loadFailed')
    } finally {
        loading.value = false
    }
}

async function save(): Promise<void> {
    saving.value = true
    generalError.value = null
    saved.value = false
    errors.value = {}

    try {
        apply(
            await api.put<Payload>('admin/settings/downloads', {
                notice: notice.value.trim() === '' ? null : notice.value,
                clients: clients.value,
            }),
        )

        saved.value = true
    } catch (error) {
        if (error instanceof ApiError && error.isValidation) {
            errors.value = error.errors
            generalError.value = t('admin.downloads.invalid')
        } else {
            generalError.value =
                error instanceof ApiError ? error.message : t('common.genericError')
        }
    } finally {
        saving.value = false
    }
}

/**
 * Drop the overrides and fall back to config/game.php.
 *
 * Confirmed first, because it discards every edit anyone has made here and
 * there is no undo for it -- the previous values are not kept anywhere.
 */
async function resetToConfig(): Promise<void> {
    if (!window.confirm(t('admin.downloads.resetConfirm'))) {
        return
    }

    resetting.value = true
    generalError.value = null
    saved.value = false
    errors.value = {}

    try {
        apply(await api.delete<Payload>('admin/settings/downloads'))
        saved.value = true
    } catch (error) {
        generalError.value = error instanceof ApiError ? error.message : t('common.genericError')
    } finally {
        resetting.value = false
    }
}

function addClient(): void {
    clients.value.push({
        name: '',
        platform: null,
        badge: null,
        version: null,
        size: null,
        updated: null,
        description: '',
        mirrors: [],
        notes: [],
    })
}

function removeClient(index: number): void {
    clients.value.splice(index, 1)
}

/**
 * Move a package up or down.
 *
 * Buttons rather than drag-and-drop: the order is the order the cards appear
 * in, there are rarely more than four, and a drag target is the one control
 * here that would be unusable from a keyboard.
 */
function moveClient(index: number, delta: number): void {
    const target = index + delta

    if (target < 0 || target >= clients.value.length) {
        return
    }

    const list = clients.value
    const [moved] = list.splice(index, 1)

    list.splice(target, 0, moved)
}

function addMirror(client: DownloadClient): void {
    client.mirrors.push({ label: '', url: '' })
}

function removeMirror(client: DownloadClient, index: number): void {
    client.mirrors.splice(index, 1)
}

function addNote(client: DownloadClient): void {
    client.notes.push('')
}

function removeNote(client: DownloadClient, index: number): void {
    client.notes.splice(index, 1)
}

onMounted(load)
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('admin.downloads.title') }}
                </h1>
                <p class="mt-0.5 text-sm text-[var(--text-secondary)]">
                    {{ t('admin.downloads.summary') }}
                </p>
            </div>

            <AppButton variant="primary" :loading="saving" :disabled="loading" @click="save">
                {{ t('admin.save') }}
            </AppButton>
        </div>

        <!--
            Where the values on screen came from. An operator who does not
            recognise them needs to know whether they are looking at their own
            config file or at somebody's edit from three months ago.
        -->
        <p class="mt-2 text-[0.8125rem] text-[var(--text-muted)]">
            <template v-if="meta.overridden">
                {{ t('admin.downloads.sourceOverride') }}
            </template>
            <template v-else>{{ t('admin.downloads.sourceConfig') }}</template>
        </p>

        <AlertMessage v-if="loadError" tone="error" class="mt-4">{{ loadError }}</AlertMessage>

        <AlertMessage v-if="generalError" tone="error" class="mt-4">
            {{ generalError }}
            <ul v-if="unplacedErrors().length > 0" class="mt-1 list-disc pl-5">
                <li v-for="message in unplacedErrors()" :key="message">{{ message }}</li>
            </ul>
        </AlertMessage>

        <AlertMessage v-if="saved" tone="success" class="mt-4">
            {{ t('admin.downloads.saved') }}
        </AlertMessage>

        <p v-if="loading" class="mt-6 text-sm text-[var(--text-secondary)]">
            {{ t('common.loading') }}
        </p>

        <template v-else>
            <section class="panel mt-5 p-4">
                <label class="field-label" for="downloads-notice">
                    {{ t('admin.downloads.notice') }}
                </label>
                <textarea
                    id="downloads-notice"
                    v-model="notice"
                    class="field-input"
                    rows="2"
                    maxlength="500"
                ></textarea>
                <p class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                    {{ t('admin.downloads.noticeHelp') }}
                </p>
                <p v-if="errorFor('notice')" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errorFor('notice') }}
                </p>
            </section>

            <!--
                One fieldset per package, so the name, the mirrors and the
                notes are announced as belonging together rather than as
                nineteen loose inputs on one page.
            -->
            <fieldset v-for="(client, index) in clients" :key="index" class="panel mt-4 p-4">
                <legend class="px-1 text-sm font-medium">
                    {{ client.name || t('admin.downloads.untitled') }}
                </legend>

                <div class="mb-3 flex flex-wrap justify-end gap-2">
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="index === 0"
                        :aria-label="t('admin.downloads.moveUp')"
                        @click="moveClient(index, -1)"
                    >
                        ↑
                    </AppButton>
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="index === clients.length - 1"
                        :aria-label="t('admin.downloads.moveDown')"
                        @click="moveClient(index, 1)"
                    >
                        ↓
                    </AppButton>
                    <AppButton variant="danger" size="sm" @click="removeClient(index)">
                        {{ t('admin.downloads.removePackage') }}
                    </AppButton>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="field-label" :for="`client-${index}-name`">
                            {{ t('admin.downloads.name') }}
                        </label>
                        <input
                            :id="`client-${index}-name`"
                            v-model="client.name"
                            class="field-input"
                            type="text"
                            maxlength="100"
                        />
                        <p
                            v-if="errorFor(`clients.${index}.name`)"
                            class="mt-1 text-[0.8125rem] text-[var(--color-down)]"
                        >
                            {{ errorFor(`clients.${index}.name`) }}
                        </p>
                    </div>

                    <div>
                        <label class="field-label" :for="`client-${index}-platform`">
                            {{ t('admin.downloads.platform') }}
                        </label>
                        <select
                            :id="`client-${index}-platform`"
                            v-model="client.platform"
                            class="field-input"
                        >
                            <!--
                                "None" is a real choice, not a prompt: a
                                package for a platform this panel has no icon
                                or label for is drawn without either, which is
                                better than it guessing.
                            -->
                            <option :value="null">{{ t('admin.downloads.platformNone') }}</option>
                            <option
                                v-for="platform in meta.platforms"
                                :key="platform"
                                :value="platform"
                            >
                                {{ platform }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="field-label" :for="`client-${index}-version`">
                            {{ t('admin.downloads.version') }}
                        </label>
                        <input
                            :id="`client-${index}-version`"
                            v-model="client.version"
                            class="field-input"
                            type="text"
                            maxlength="50"
                        />
                    </div>

                    <div>
                        <label class="field-label" :for="`client-${index}-size`">
                            {{ t('admin.downloads.size') }}
                        </label>
                        <input
                            :id="`client-${index}-size`"
                            v-model="client.size"
                            class="field-input"
                            type="text"
                            maxlength="50"
                        />
                    </div>

                    <div>
                        <label class="field-label" :for="`client-${index}-updated`">
                            {{ t('admin.downloads.updated') }}
                        </label>
                        <input
                            :id="`client-${index}-updated`"
                            v-model="client.updated"
                            class="field-input"
                            type="text"
                            maxlength="50"
                        />
                    </div>

                    <div>
                        <label class="field-label" :for="`client-${index}-badge`">
                            {{ t('admin.downloads.badge') }}
                        </label>
                        <input
                            :id="`client-${index}-badge`"
                            v-model="client.badge"
                            class="field-input"
                            type="text"
                            maxlength="24"
                        />
                    </div>
                </div>

                <div class="mt-3">
                    <label class="field-label" :for="`client-${index}-description`">
                        {{ t('admin.downloads.description') }}
                    </label>
                    <textarea
                        :id="`client-${index}-description`"
                        v-model="client.description"
                        class="field-input"
                        rows="2"
                        maxlength="1000"
                    ></textarea>
                </div>

                <h3 class="mt-4 text-sm font-medium">{{ t('admin.downloads.mirrors') }}</h3>
                <p class="text-[0.8125rem] text-[var(--text-muted)]">
                    {{ t('admin.downloads.mirrorsHelp') }}
                </p>

                <div
                    v-for="(mirror, m) in client.mirrors"
                    :key="m"
                    class="mt-2 grid gap-2 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto]"
                >
                    <div>
                        <input
                            v-model="mirror.label"
                            class="field-input"
                            type="text"
                            maxlength="60"
                            :placeholder="t('admin.downloads.mirrorLabel')"
                            :aria-label="t('admin.downloads.mirrorLabel')"
                        />
                        <p
                            v-if="errorFor(`clients.${index}.mirrors.${m}.label`)"
                            class="mt-1 text-[0.8125rem] text-[var(--color-down)]"
                        >
                            {{ errorFor(`clients.${index}.mirrors.${m}.label`) }}
                        </p>
                    </div>

                    <div>
                        <input
                            v-model.trim="mirror.url"
                            class="field-input"
                            type="url"
                            maxlength="2048"
                            inputmode="url"
                            placeholder="https://"
                            :aria-label="t('admin.downloads.mirrorUrl')"
                        />
                        <p
                            v-if="errorFor(`clients.${index}.mirrors.${m}.url`)"
                            class="mt-1 text-[0.8125rem] text-[var(--color-down)]"
                        >
                            {{ errorFor(`clients.${index}.mirrors.${m}.url`) }}
                        </p>
                    </div>

                    <AppButton
                        variant="secondary"
                        size="sm"
                        :aria-label="t('admin.downloads.removeMirror')"
                        @click="removeMirror(client, m)"
                    >
                        {{ t('admin.remove') }}
                    </AppButton>
                </div>

                <AppButton variant="secondary" size="sm" class="mt-2" @click="addMirror(client)">
                    {{ t('admin.downloads.addMirror') }}
                </AppButton>

                <h3 class="mt-4 text-sm font-medium">{{ t('admin.downloads.notes') }}</h3>
                <p class="text-[0.8125rem] text-[var(--text-muted)]">
                    {{ t('admin.downloads.notesHelp') }}
                </p>

                <div
                    v-for="(_, n) in client.notes"
                    :key="n"
                    class="mt-2 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]"
                >
                    <div>
                        <input
                            v-model="client.notes[n]"
                            class="field-input"
                            type="text"
                            maxlength="500"
                            :aria-label="t('admin.downloads.note')"
                        />
                        <p
                            v-if="errorFor(`clients.${index}.notes.${n}`)"
                            class="mt-1 text-[0.8125rem] text-[var(--color-down)]"
                        >
                            {{ errorFor(`clients.${index}.notes.${n}`) }}
                        </p>
                    </div>

                    <AppButton variant="secondary" size="sm" @click="removeNote(client, n)">
                        {{ t('admin.remove') }}
                    </AppButton>
                </div>

                <AppButton variant="secondary" size="sm" class="mt-2" @click="addNote(client)">
                    {{ t('admin.downloads.addNote') }}
                </AppButton>
            </fieldset>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <AppButton variant="secondary" @click="addClient">
                    {{ t('admin.downloads.addPackage') }}
                </AppButton>

                <AppButton variant="primary" :loading="saving" @click="save">
                    {{ t('admin.save') }}
                </AppButton>

                <AppButton
                    v-if="meta.overridden"
                    variant="danger"
                    class="ml-auto"
                    :loading="resetting"
                    @click="resetToConfig"
                >
                    {{ t('admin.downloads.reset') }}
                </AppButton>
            </div>
        </template>
    </div>
</template>
