<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AlertMessage from '../../components/ui/AlertMessage.vue'
import AppButton from '../../components/ui/AppButton.vue'
import WikiIcon, { WIKI_ICON_NAMES } from '../../components/ui/WikiIcon.vue'
import { api, ApiError } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * Naming one wiki section.
 *
 * Four fields, which is all a section is: what it is called, the line under
 * that, the mark beside it and where it sorts. Its pages are not here -- they
 * are the other screen -- and its address is editable only while creating one,
 * for the reason a page's is: renaming a section would move every page in it
 * and break every link to every one of them.
 */
const { t } = useTranslation()

const route = useRoute()
const router = useRouter()

/** The section being edited, or null when this is a new one. */
const slug = computed(() => (typeof route.params.slug === 'string' ? route.params.slug : null))

const isNew = computed(() => slug.value === null)

/**
 * The square a section's art has to be.
 *
 * The same pair as config/wiki.php's `section_image`, kept in two places
 * because they are two languages: the browser checks before it uploads, so
 * that an image exported at the wrong size is refused here rather than after
 * eight megabytes have crossed the wire. The server is still the one that
 * decides -- this only saves the trip.
 */
const IMAGE_SIZE = 1080

const form = reactive({
    slug: '',
    title: '',
    description: '',
    icon: '',
    /** A URL this panel's own uploader produced, or '' for the placeholder. */
    image: '',
    /*
     * Both, because Vue hands back whichever the box currently is: v-model on
     * an <input type="number"> casts what is typed to a number as soon as it
     * parses as one, and leaves the empty box as the empty string. Normalised
     * where it is sent rather than watched here -- see save().
     */
    sort_order: '' as string | number,
})

const errors = reactive<Record<string, string>>({})
const source = ref<'file' | 'database' | 'both' | null>(null)
const pages = ref(0)

const loading = ref(false)
const saving = ref(false)
const generalError = ref<string | null>(null)

const imagePicker = ref<HTMLInputElement | null>(null)
const uploadingImage = ref(false)
const draggingImage = ref(false)
const imageError = ref<string | null>(null)

const icons = WIKI_ICON_NAMES

async function load(): Promise<void> {
    if (slug.value === null) {
        return
    }

    loading.value = true

    try {
        const payload = await api.get<{
            data: {
                slug: string
                title: string
                description: string | null
                icon: string | null
                image: string | null
                sort_order: number | null
                pages: number
                source: 'file' | 'database' | 'both'
            }
        }>(`admin/wiki/sections/${slug.value}`)

        form.slug = payload.data.slug
        form.title = payload.data.title
        form.description = payload.data.description ?? ''
        form.icon = payload.data.icon ?? ''
        form.image = payload.data.image ?? ''
        form.sort_order = payload.data.sort_order === null ? '' : String(payload.data.sort_order)
        source.value = payload.data.source
        pages.value = payload.data.pages
    } catch {
        generalError.value = t('admin.wiki.sections.loadFailedOne')
    } finally {
        loading.value = false
    }
}

/*
|------------------------------------------------------------------------------
| The section's art
|------------------------------------------------------------------------------
*/

/**
 * Take an image, check the square, and hand the page its URL.
 *
 * The dimension check is done here as well as on the server because the file
 * is large and the answer is instant: reading it as a bitmap costs nothing
 * next to uploading it only to be told the shape was wrong. A browser that
 * cannot decode it simply uploads and lets the server answer.
 */
async function uploadImage(file: File): Promise<void> {
    imageError.value = null

    const measured = await measure(file)

    if (measured !== null && (measured.width !== IMAGE_SIZE || measured.height !== IMAGE_SIZE)) {
        imageError.value = t('admin.wiki.sections.imageWrongSize', {
            width: measured.width,
            height: measured.height,
            size: IMAGE_SIZE,
        })

        return
    }

    uploadingImage.value = true

    try {
        const body = new FormData()

        body.append('image', file)

        const payload = await api.post<{ data: { url: string } }>('admin/wiki/sections/image', body)

        form.image = payload.data.url
    } catch (error) {
        imageError.value =
            error instanceof ApiError ? error.message : t('admin.wiki.editor.uploadFailed')
    } finally {
        uploadingImage.value = false
    }
}

/** A file's pixel size, or null when this browser will not say. */
async function measure(file: File): Promise<{ width: number; height: number } | null> {
    try {
        const bitmap = await createImageBitmap(file)
        const size = { width: bitmap.width, height: bitmap.height }

        bitmap.close()

        return size
    } catch {
        return null
    }
}

function onImagePicked(event: Event): void {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]

    if (file !== undefined) {
        void uploadImage(file)
    }

    // Cleared so that picking the same file twice in a row fires again.
    input.value = ''
}

function onImageDropped(event: DragEvent): void {
    draggingImage.value = false

    const file = [...(event.dataTransfer?.files ?? [])].find((candidate) =>
        candidate.type.startsWith('image/'),
    )

    if (file !== undefined) {
        void uploadImage(file)
    }
}

/**
 * The order box, as a number, or null for "no declared order".
 *
 * Through String() because the field is a number as often as it is a string,
 * and .trim() on a number is a TypeError -- which the form would report as
 * "something went wrong" without ever reaching the server.
 */
function declaredOrder(): number | null {
    const typed = String(form.sort_order).trim()

    return typed === '' ? null : Number(typed)
}

async function save(): Promise<void> {
    saving.value = true
    generalError.value = null
    Object.keys(errors).forEach((key) => delete errors[key])

    try {
        await api.post('admin/wiki/sections', {
            slug: form.slug,
            title: form.title,
            description: form.description.trim() === '' ? null : form.description.trim(),
            // The empty option in the select is "no icon", which draws the
            // book. Sent as null rather than as an empty string, which the
            // server would refuse as a name it does not have.
            icon: form.icon === '' ? null : form.icon,
            image: form.image === '' ? null : form.image,
            /*
             * An empty box means "no declared order", which sorts the section
             * after every section that has one -- the same rule
             * `_category.md` follows. Sent as null rather than as 0, which
             * would pin it to the top.
             */
            sort_order: declaredOrder(),
        })

        await router.push('/admin/wiki/sections')
    } catch (error) {
        if (error instanceof ApiError && error.isValidation) {
            for (const [field, messages] of Object.entries(error.errors)) {
                errors[field] = messages[0]
            }

            generalError.value = t('admin.wiki.invalid')
        } else {
            generalError.value =
                error instanceof ApiError ? error.message : t('common.genericError')
        }
    } finally {
        saving.value = false
    }
}

onMounted(load)
</script>

<template>
    <div>
        <h1 class="text-xl font-semibold tracking-tight">
            {{ isNew ? t('admin.wiki.sections.addTitle') : t('admin.wiki.sections.editTitle') }}
        </h1>
        <p v-if="slug" class="mt-0.5 text-sm text-[var(--text-secondary)]">/wiki/{{ slug }}</p>

        <!--
            Said on the page it is true of, as the page editor says its own
            version: describing a section that is a directory in version
            control is the moment it stops reading its `_category.md`.
        -->
        <AlertMessage v-if="source === 'file'" tone="warning" class="mt-4">
            {{ t('admin.wiki.sections.firstEditNote') }}
        </AlertMessage>

        <!--
            A section with no pages is not on the wiki yet. Worth saying to
            somebody who has just created one and is about to go looking.
        -->
        <AlertMessage v-if="!isNew && pages === 0" tone="info" class="mt-4">
            {{ t('admin.wiki.sections.emptyNote') }}
        </AlertMessage>

        <AlertMessage v-if="generalError" tone="error" class="mt-4">
            {{ generalError }}
        </AlertMessage>

        <p v-if="loading" class="mt-6 text-sm text-[var(--text-secondary)]">
            {{ t('common.loading') }}
        </p>

        <form v-else class="panel mt-5 space-y-4 p-4" novalidate @submit.prevent="save">
            <div>
                <label class="field-label" for="section-slug">
                    {{ t('admin.wiki.sections.address') }}
                </label>
                <input
                    id="section-slug"
                    v-model.trim="form.slug"
                    class="field-input"
                    type="text"
                    maxlength="96"
                    :disabled="!isNew"
                    required
                />
                <p class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                    {{
                        isNew
                            ? t('admin.wiki.sections.addressHelp')
                            : t('admin.wiki.sections.addressFixed')
                    }}
                </p>
                <p v-if="errors.slug" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errors.slug }}
                </p>
            </div>

            <div>
                <label class="field-label" for="section-title">
                    {{ t('admin.wiki.pageTitle') }}
                </label>
                <input
                    id="section-title"
                    v-model="form.title"
                    class="field-input"
                    type="text"
                    maxlength="191"
                    required
                />
                <p v-if="errors.title" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errors.title }}
                </p>
            </div>

            <div>
                <label class="field-label" for="section-description">
                    {{ t('admin.wiki.sections.description') }}
                </label>
                <input
                    id="section-description"
                    v-model="form.description"
                    class="field-input"
                    type="text"
                    maxlength="500"
                />
                <p class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                    {{ t('admin.wiki.sections.descriptionHelp') }}
                </p>
                <p v-if="errors.description" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errors.description }}
                </p>
            </div>

            <!--
                The section's art, which is what a reader actually sees of a
                section: the tiles on /wiki are a grid of squares with the
                name written over them, and this is the square.
            -->
            <div>
                <span class="field-label">{{ t('admin.wiki.sections.image') }}</span>

                <div
                    class="relative mt-1 flex flex-wrap items-center gap-3 rounded-[var(--radius-panel)] border border-dashed border-[var(--border-strong)] p-3"
                    @dragenter.prevent="draggingImage = true"
                    @dragover.prevent
                    @dragleave="draggingImage = false"
                    @drop.prevent="onImageDropped"
                >
                    <!--
                        Drawn at the shape it will be drawn at, because the
                        thing being chosen is a tile.
                    -->
                    <img
                        v-if="form.image"
                        :src="form.image"
                        alt=""
                        class="size-20 shrink-0 rounded-[var(--radius-panel)] object-cover"
                    />

                    <div
                        v-else
                        class="grid size-20 shrink-0 place-items-center rounded-[var(--radius-panel)] bg-[var(--surface-sunken)] text-[0.6875rem] text-[var(--text-muted)]"
                    >
                        {{ t('admin.wiki.sections.imageNone') }}
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="text-[0.8125rem] text-[var(--text-muted)]">
                            {{ t('admin.wiki.sections.imageHelp', { size: IMAGE_SIZE }) }}
                        </p>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <AppButton
                                variant="secondary"
                                size="sm"
                                :loading="uploadingImage"
                                @click="imagePicker?.click()"
                            >
                                {{
                                    form.image
                                        ? t('admin.wiki.sections.imageReplace')
                                        : t('admin.wiki.sections.imageAdd')
                                }}
                            </AppButton>

                            <!--
                                Clears the field only. The file stays on disk:
                                another section may be using the same square,
                                since identical images are one file.
                            -->
                            <AppButton
                                v-if="form.image"
                                variant="secondary"
                                size="sm"
                                @click="form.image = ''"
                            >
                                {{ t('admin.remove') }}
                            </AppButton>
                        </div>
                    </div>

                    <div v-if="draggingImage" class="md-drop">
                        {{ t('admin.wiki.editor.dropHere') }}
                    </div>
                </div>

                <p v-if="imageError" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ imageError }}
                </p>
                <p v-if="errors.image" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errors.image }}
                </p>

                <input
                    ref="imagePicker"
                    type="file"
                    class="sr-only"
                    accept="image/*"
                    @change="onImagePicked"
                />
            </div>

            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem]">
                <div>
                    <label class="field-label" for="section-icon">
                        {{ t('admin.wiki.sections.icon') }}
                    </label>

                    <!--
                        A list rather than a text box, and drawn beside it: the
                        set is fixed, and a name typed wrong would silently
                        render as the fallback book.
                    -->
                    <div class="flex items-center gap-2">
                        <WikiIcon
                            :name="form.icon"
                            class="size-5 shrink-0 text-[var(--text-secondary)]"
                        />

                        <select
                            id="section-icon"
                            v-model="form.icon"
                            class="field-input min-w-0 flex-1"
                        >
                            <option value="">{{ t('admin.wiki.sections.iconDefault') }}</option>
                            <option v-for="name in icons" :key="name" :value="name">
                                {{ t(`admin.wiki.sections.icons.${name}`) }}
                            </option>
                        </select>
                    </div>

                    <p v-if="errors.icon" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                        {{ errors.icon }}
                    </p>
                </div>

                <div>
                    <label class="field-label" for="section-order">
                        {{ t('admin.wiki.order') }}
                    </label>
                    <input
                        id="section-order"
                        v-model="form.sort_order"
                        class="field-input"
                        type="number"
                        min="0"
                        max="9999"
                    />
                    <p
                        v-if="errors.sort_order"
                        class="mt-1 text-[0.8125rem] text-[var(--color-down)]"
                    >
                        {{ errors.sort_order }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="saving">
                    {{ t('admin.save') }}
                </AppButton>

                <AppButton variant="secondary" to="/admin/wiki/sections">
                    {{ t('common.back') }}
                </AppButton>
            </div>
        </form>
    </div>
</template>
