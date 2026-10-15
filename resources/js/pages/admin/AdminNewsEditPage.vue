<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AlertMessage from '../../components/ui/AlertMessage.vue'
import AppButton from '../../components/ui/AppButton.vue'
import NewsTag from '../../components/ui/NewsTag.vue'
import { NEWS_TAG_SUGGESTIONS } from '../../blocks/tags'
import { api, ApiError } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * Writing or editing one article.
 *
 * One component for both, because the form is the same and the only
 * difference is which request it sends. Splitting them would mean keeping two
 * copies of the same field list in step.
 *
 * The body is Markdown. It is stored exactly as typed and rendered through
 * ContentRenderer on the way out, which strips raw HTML -- the legacy editor
 * stored rich-text HTML and echoed it into the page, which is what made it a
 * stored-XSS vector reachable by every administrator.
 */
const { t } = useTranslation()

const route = useRoute()
const router = useRouter()

const id = computed(() => {
    const raw = route.params.id

    return typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null
})

const isNew = computed(() => id.value === null)

const form = reactive({ title: '', body: '', link: '', author: '', tag: '' })
const errors = reactive<Record<string, string>>({})

const loading = ref(false)
const saving = ref(false)
const generalError = ref<string | null>(null)

async function load(): Promise<void> {
    if (id.value === null) {
        return
    }

    loading.value = true

    try {
        const payload = await api.get<{
            data: {
                title: string
                body?: string
                link: string | null
                author: string
                tag: string | null
            }
        }>(`news/${id.value}/edit`)

        form.title = payload.data.title
        form.body = payload.data.body ?? ''
        form.link = payload.data.link ?? ''
        form.author = payload.data.author
        form.tag = payload.data.tag ?? ''
    } catch {
        generalError.value = t('admin.news.loadFailed')
    } finally {
        loading.value = false
    }
}

async function save(): Promise<void> {
    saving.value = true
    generalError.value = null
    Object.keys(errors).forEach((key) => delete errors[key])

    /*
     * An empty link is sent as null rather than as an empty string: the rule
     * behind it is `nullable|url`, which an empty string does not satisfy, and
     * "I did not set a read-more link" is not a validation failure.
     */
    const body = {
        title: form.title,
        body: form.body,
        link: form.link.trim() === '' ? null : form.link.trim(),
        author: form.author.trim() === '' ? null : form.author.trim(),
        tag: form.tag.trim() === '' ? null : form.tag.trim(),
    }

    try {
        if (id.value === null) {
            await api.post('news', body)
        } else {
            await api.put(`news/${id.value}`, body)
        }

        await router.push('/admin/news')
    } catch (error) {
        if (error instanceof ApiError && error.isValidation) {
            for (const [field, messages] of Object.entries(error.errors)) {
                errors[field] = messages[0]
            }

            generalError.value = t('admin.news.invalid')
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
            {{ isNew ? t('admin.news.writeTitle') : t('admin.news.editTitle') }}
        </h1>
        <p class="mt-0.5 text-sm text-[var(--text-secondary)]">
            {{ t('admin.news.bodyIsMarkdown') }}
        </p>

        <AlertMessage v-if="generalError" tone="error" class="mt-4">
            {{ generalError }}
        </AlertMessage>

        <p v-if="loading" class="mt-6 text-sm text-[var(--text-secondary)]">
            {{ t('common.loading') }}
        </p>

        <form v-else class="panel mt-5 space-y-4 p-4" novalidate @submit.prevent="save">
            <div>
                <label class="field-label" for="news-title">{{
                    t('admin.news.articleTitle')
                }}</label>
                <input
                    id="news-title"
                    v-model="form.title"
                    class="field-input"
                    type="text"
                    maxlength="100"
                    required
                />
                <p v-if="errors.title" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errors.title }}
                </p>
            </div>

            <div>
                <label class="field-label" for="news-body">{{ t('admin.news.body') }}</label>
                <textarea
                    id="news-body"
                    v-model="form.body"
                    class="field-input font-mono text-[0.8125rem]"
                    rows="16"
                    required
                ></textarea>
                <p v-if="errors.body" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errors.body }}
                </p>
            </div>

            <div>
                <label class="field-label" for="news-tag">{{ t('admin.news.tag') }}</label>

                <div class="flex flex-wrap items-center gap-2">
                    <!--
                        A text box with suggestions rather than a dropdown: the
                        panel cannot know whether this server posts auctions,
                        guild weeks or patch notes, and a list of five would be
                        wrong for most of them. The common words are offered,
                        and the ones this panel recognises get a colour and a
                        mark -- which is what the pill beside the field shows,
                        before the article is saved rather than after.
                    -->
                    <input
                        id="news-tag"
                        v-model.trim="form.tag"
                        class="field-input sm:max-w-xs"
                        type="text"
                        maxlength="32"
                        list="news-tag-suggestions"
                        autocomplete="off"
                    />

                    <datalist id="news-tag-suggestions">
                        <option v-for="suggestion in NEWS_TAG_SUGGESTIONS" :key="suggestion">
                            {{ suggestion }}
                        </option>
                    </datalist>

                    <NewsTag v-if="form.tag" :tag="form.tag" />
                </div>

                <p class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                    {{ t('admin.news.tagHelp') }}
                </p>

                <p v-if="errors.tag" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                    {{ errors.tag }}
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="news-link">{{ t('admin.news.link') }}</label>
                    <input
                        id="news-link"
                        v-model.trim="form.link"
                        class="field-input"
                        type="url"
                        maxlength="100"
                        placeholder="https://"
                    />
                    <p class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('admin.news.linkHelp') }}
                    </p>
                    <p v-if="errors.link" class="mt-1 text-[0.8125rem] text-[var(--color-down)]">
                        {{ errors.link }}
                    </p>
                </div>

                <div>
                    <label class="field-label" for="news-author">
                        {{ t('admin.news.author') }}
                    </label>
                    <input
                        id="news-author"
                        v-model.trim="form.author"
                        class="field-input"
                        type="text"
                        maxlength="100"
                    />
                    <p class="mt-1 text-[0.8125rem] text-[var(--text-muted)]">
                        {{ t('admin.news.authorHelp') }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="saving">
                    {{ t('admin.save') }}
                </AppButton>

                <AppButton variant="secondary" to="/admin/news">
                    {{ t('common.back') }}
                </AppButton>
            </div>
        </form>
    </div>
</template>
