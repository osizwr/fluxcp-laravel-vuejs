<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import AlertMessage from '../../components/ui/AlertMessage.vue'
import AppButton from '../../components/ui/AppButton.vue'
import MarkdownEditor from '../../components/ui/MarkdownEditor.vue'
import { api, ApiError } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * Writing or editing one wiki page.
 *
 * The section and the address are the URL the page will live at, so they are
 * editable only while creating one. Changing them afterwards would not move a
 * page, it would write a second one and leave every link to the first
 * pointing at what is now a different document -- which is why that is a
 * delete and a create rather than an edit.
 */
const { t } = useTranslation()

const route = useRoute()
const router = useRouter()

/** The page being edited, or null when this is a new one. */
const path = computed(() => {
    const category = route.params.category
    const slug = route.params.slug

    return typeof category === 'string' && typeof slug === 'string' ? `${category}/${slug}` : null
})

const isNew = computed(() => path.value === null)

const form = reactive({
    /*
     * Chosen from the sections that exist rather than typed. Prefilled after
     * they load, from the section the page list was narrowed to -- see
     * loadSections() -- and left for the author to pick when it was not.
     */
    category: '',
    slug: '',
    title: '',
    summary: '',
    /*
     * Both, because Vue hands back whichever the box currently is: v-model on
     * an <input type="number"> casts what is typed to a number as soon as it
     * parses as one, and leaves the empty box as the empty string. Normalised
     * where it is sent rather than watched here -- see save().
     */
    sort_order: '' as string | number,
    body: '',
})

const errors = reactive<Record<string, string>>({})
const source = ref<'file' | 'database' | 'both' | null>(null)

/**
 * The sections a page can be written into.
 *
 * A list rather than a box to type in, because a section is a thing that
 * exists -- it has a name, a description and an icon, all of which are edited
 * one screen over -- and a typed one used to bring a new section into being
 * out of a typo, titled from whatever had been typed. Adding a section is now
 * its own deliberate act, so this only has to offer the ones there are.
 */
const sections = ref<{ slug: string; title: string }[]>([])

/**
 * Those sections, with the page's own kept in the list.
 *
 * An edited page is shown the section it is in even if that section would not
 * be offered for a new page -- it cannot be changed here anyway, and a select
 * whose value is not among its options draws as an empty box, which would read
 * as a page belonging to nothing.
 */
const choices = computed(() => {
    const options = sections.value.map((section) => ({ ...section }))

    if (!isNew.value && form.category !== '' && !options.some((o) => o.slug === form.category)) {
        options.unshift({ slug: form.category, title: form.category })
    }

    return options
})

const loading = ref(false)
const saving = ref(false)
const generalError = ref<string | null>(null)

/**
 * Load the sections, and start on one if the list came in through the query.
 *
 * The prefill is checked against what came back rather than trusted, so a
 * hand-edited `?section=` cannot put a value in the field that is not one of
 * the choices offered beside it.
 */
async function loadSections(): Promise<void> {
    try {
        const payload = await api.get<{ data: { sections: { slug: string; title: string }[] } }>(
            'admin/wiki/sections',
        )

        sections.value = payload.data.sections
    } catch {
        generalError.value = t('admin.wiki.sections.loadFailed')

        return
    }

    const wanted = route.query.section

    if (
        isNew.value &&
        typeof wanted === 'string' &&
        sections.value.some((s) => s.slug === wanted)
    ) {
        form.category = wanted
    }
}

async function load(): Promise<void> {
    if (path.value === null) {
        return
    }

    loading.value = true

    try {
        const payload = await api.get<{
            data: {
                category: string
                slug: string
                title: string
                summary: string | null
                sort_order: number | null
                body: string
                source: 'file' | 'database' | 'both'
            }
        }>(`admin/wiki/${path.value}`)

        form.category = payload.data.category
        form.slug = payload.data.slug
        form.title = payload.data.title
        form.summary = payload.data.summary ?? ''
        form.sort_order = payload.data.sort_order === null ? '' : String(payload.data.sort_order)
        form.body = payload.data.body
        source.value = payload.data.source
    } catch {
        generalError.value = t('admin.wiki.loadFailed')
    } finally {
        loading.value = false
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
        await api.post('admin/wiki', {
            category: form.category,
            slug: form.slug,
            title: form.title,
            summary: form.summary.trim() === '' ? null : form.summary.trim(),
            /*
             * An empty box means "no declared order", which sorts the page
             * after every page that has one -- the same rule the front matter
             * on disk follows. Sent as null rather than as 0, which would pin
             * it to the top.
             */
            sort_order: declaredOrder(),
            body: form.body,
        })

        await router.push('/admin/wiki/pages')
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

onMounted(() => {
    void loadSections()
    void load()
})
</script>

<template>
    <div>
        <h1 class="text-xl font-semibold tracking-tight">
            {{ isNew ? t('admin.wiki.writeTitle') : t('admin.wiki.editTitle') }}
        </h1>
        <p v-if="path" class="mt-0.5 text-sm text-[var(--text-secondary)]">/wiki/{{ path }}</p>

        <!--
            Said on the page it is true of: editing a file-backed page is the
            moment it stops tracking version control, and that is worth
            knowing before the first save rather than after it.
        -->
        <AlertMessage v-if="source === 'file'" tone="warning" class="mt-4">
            {{ t('admin.wiki.firstEditNote') }}
        </AlertMessage>

        <AlertMessage v-if="generalError" tone="error" class="mt-4">
            {{ generalError }}
        </AlertMessage>

        <p v-if="loading" class="mt-6 text-sm text-[var(--text-secondary)]">
            {{ t('common.loading') }}
        </p>

        <form v-else class="panel mt-5 space-y-4 p-4" novalidate @submit.prevent="save">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="wiki-category">
                        {{ t('admin.wiki.section') }}
                    </label>

                    <!--
                        The sections that exist, not a box to type one into.
                        Disabled on an edit for the reason the address below is:
                        moving a page is a delete and a create, not an edit.
                    -->
                    <select
                        id="wiki-category"
                        v-model="form.category"
                        class="field-input"
                        :disabled="!isNew"
                        required
                    >
                        <option value="" disabled>{{ t('admin.wiki.sectionChoose') }}</option>
                        <option
                            v-for="section in choices"
                            :key="section.slug"
                            :value="section.slug"
                        >
                            {{ section.title }} &mdash; /{{ section.slug }}
                        </option>
                    </select>

                    <!--
                        A wiki with no sections in it yet. The way on is the
                        section screen, and saying so here is the difference
                        between a dead end and a next step.
                    -->
                    <p
                        v-if="isNew && choices.length === 0"
                        class="mt-1 text-[0.8125rem] text-[var(--text-secondary)]"
                    >
                        {{ t('admin.wiki.sections.empty') }}
                        <RouterLink
                            to="/admin/wiki/sections/new"
                            class="underline underline-offset-2 hover:text-[var(--text-primary)]"
                        >
                            {{ t('admin.wiki.sections.add') }}
                        </RouterLink>
                    </p>

                    <!--
                        Nothing under it on an edit. The address beside it says
                        "fixed once created" and the select is visibly greyed
                        out; the same sentence printed twice in one row reads
                        as two rules rather than as one.
                    -->
                    <p v-else-if="isNew" class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('admin.wiki.sectionHelp') }}
                    </p>

                    <p
                        v-if="errors.category"
                        class="mt-1 text-[0.8125rem] text-[var(--color-down)]"
                    >
                        {{ errors.category }}
                    </p>
                </div>

                <div>
                    <label class="field-label" for="wiki-slug">{{ t('admin.wiki.address') }}</label>
                    <input
                        id="wiki-slug"
                        v-model.trim="form.slug"
                        class="field-input"
                        type="text"
                        maxlength="96"
                        :disabled="!isNew"
                        required
                    />
                    <p class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                        {{ isNew ? t('admin.wiki.addressHelp') : t('admin.wiki.addressFixed') }}
                    </p>
                    <p v-if="errors.slug" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                        {{ errors.slug }}
                    </p>
                </div>
            </div>

            <div>
                <label class="field-label" for="wiki-title">{{ t('admin.wiki.pageTitle') }}</label>
                <input
                    id="wiki-title"
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

            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem]">
                <div>
                    <label class="field-label" for="wiki-summary">
                        {{ t('admin.wiki.pageSummary') }}
                    </label>
                    <input
                        id="wiki-summary"
                        v-model="form.summary"
                        class="field-input"
                        type="text"
                        maxlength="500"
                    />
                </div>

                <div>
                    <label class="field-label" for="wiki-order">{{ t('admin.wiki.order') }}</label>
                    <input
                        id="wiki-order"
                        v-model="form.sort_order"
                        class="field-input"
                        type="number"
                        min="0"
                        max="9999"
                    />
                </div>
            </div>

            <div>
                <label class="field-label" for="wiki-body">{{ t('admin.wiki.body') }}</label>

                <!--
                    The document stays Markdown -- see MarkdownEditor for why
                    this is not a block editor -- with the conveniences built
                    around it: images that upload when dropped, a slash menu,
                    and a preview rendered by the renderer that publishes.
                -->
                <MarkdownEditor v-model="form.body" input-id="wiki-body" :rows="22" />

                <p class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                    {{ t('admin.wiki.bodyHelp') }}
                </p>
                <p v-if="errors.body" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errors.body }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="saving">
                    {{ t('admin.save') }}
                </AppButton>

                <AppButton variant="secondary" to="/admin/wiki/pages">
                    {{ t('common.back') }}
                </AppButton>
            </div>
        </form>
    </div>
</template>
