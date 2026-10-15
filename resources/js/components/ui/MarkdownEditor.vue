<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { api, ApiError, request } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * A writing surface for the Markdown this panel stores.
 *
 * ---------------------------------------------------------------------------
 * Why this is a textarea and not a block editor
 * ---------------------------------------------------------------------------
 *
 * What a page is, here, is Markdown -- in version control on disk, or in a
 * row of `wiki_pages`, and rendered with raw HTML stripped on the way out.
 * That is a decision made for good reasons elsewhere (see ContentRenderer and
 * config/wiki.php) and this editor is downstream of it.
 *
 * A WYSIWYG surface would therefore be a second representation of the page,
 * converted to Markdown on save and back again on open, and every round trip
 * is a chance to quietly lose something a file on disk said. So the document
 * stays the text, and the conveniences people actually want from a modern
 * editor are built around it: a toolbar, a slash menu, images that upload
 * themselves when dropped, and a preview that is not a guess.
 *
 * ---------------------------------------------------------------------------
 * The preview is rendered by the server
 * ---------------------------------------------------------------------------
 *
 * Deliberately, and it is the reason there is no Markdown library in this
 * project. A renderer in the browser would be a second opinion about what the
 * page says, and the first time the two disagreed would be the first time
 * somebody published something they had not actually seen. The preview posts
 * the body to the same ContentRenderer the published page goes through, so it
 * strips the HTML that will be stripped and refuses the links that will be
 * refused.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string
        /** Where an image goes. The response carries the URL to write in. */
        uploadPath?: string
        /** Where the body is rendered. Must be the real renderer. */
        previewPath?: string
        rows?: number
        inputId?: string
    }>(),
    {
        uploadPath: 'admin/wiki/images',
        previewPath: 'admin/wiki/preview',
        rows: 22,
        inputId: 'markdown-body',
    },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const { t } = useTranslation()

/** The stroke the toolbar's two drawn icons share, as WikiIcon's do. */
const STROKE = {
    fill: 'none',
    stroke: 'currentColor',
    'stroke-width': '1.6',
    'stroke-linecap': 'round',
    'stroke-linejoin': 'round',
} as const

const area = ref<HTMLTextAreaElement | null>(null)
const picker = ref<HTMLInputElement | null>(null)

/*
 * Which pane is showing. Only consulted below the width at which both fit
 * side by side -- see the template -- so on a desktop this is never read and
 * the preview is simply always there.
 */
const pane = ref<'write' | 'preview'>('write')

const uploading = ref(0)
const uploadError = ref<string | null>(null)
const dragging = ref(false)

const html = ref('')
const previewFailed = ref(false)

/*
 * The blocks, in the order the menu offers them.
 *
 * `insert` is written at the start of the current line for the ones that are
 * a line prefix, and at the cursor for the ones that are not. `caret` is where
 * to leave the cursor, counted from the start of what was inserted, for the
 * two that wrap something: a code fence and a link.
 */
interface Block {
    key: string
    /** Written into the document. Absent on the one that opens a file picker. */
    insert?: string
    /** Belongs at the start of a line, and starts a new one if it has to. */
    line?: boolean
    /** Where to leave the cursor, counted back from the end of the insert. */
    caret?: number
    upload?: boolean
}

const BLOCKS: Block[] = [
    { key: 'heading', insert: '## ', line: true },
    { key: 'subheading', insert: '### ', line: true },
    { key: 'bullets', insert: '- ', line: true },
    { key: 'numbers', insert: '1. ', line: true },
    { key: 'quote', insert: '> ', line: true },
    { key: 'code', insert: '```\n\n```\n', line: true, caret: 5 },
    { key: 'table', insert: '| | |\n| --- | --- |\n| | |\n', line: true, caret: 25 },
    { key: 'divider', insert: '---\n', line: true },
    { key: 'link', insert: '[]()', caret: 3 },
    { key: 'image', upload: true },
]

/** One block by name, so a button is not a line number in disguise. */
function block(key: string): Block {
    return BLOCKS.find((candidate) => candidate.key === key) ?? BLOCKS[0]
}

/** The slash menu: open on `/` at the start of a line, filtered as you type. */
const menu = reactive({ open: false, at: 0, query: '', index: 0 })

const matches = computed(() =>
    BLOCKS.filter((block) => {
        const query = menu.query.toLowerCase()

        return (
            query === '' ||
            block.key.startsWith(query) ||
            t(`admin.wiki.editor.blocks.${block.key}`).toLowerCase().includes(query)
        )
    }),
)

/*
|------------------------------------------------------------------------------
| Changing the text
|------------------------------------------------------------------------------
*/

/**
 * Replace a span of the body and put the cursor somewhere sensible.
 *
 * Everything that writes into the document goes through here, because the
 * value is owned by the parent: the textarea is told what it says on the next
 * tick, which is also when the cursor can be placed.
 */
function replaceRange(start: number, end: number, text: string, caret?: number): void {
    const value = props.modelValue

    emit('update:modelValue', value.slice(0, start) + text + value.slice(end))

    void nextTick(() => {
        const element = area.value

        if (element === null) {
            return
        }

        const at = caret ?? start + text.length

        element.focus()
        element.setSelectionRange(at, at)
    })
}

/** The start of the line the cursor is on. */
function lineStart(at: number): number {
    return props.modelValue.lastIndexOf('\n', at - 1) + 1
}

/**
 * Add or remove a line prefix on every line the selection touches.
 *
 * Toggling rather than only adding, so the heading button takes a heading back
 * off -- which is what pressing it twice is obviously meant to do, and the
 * alternative is a document full of `## ## `.
 */
function togglePrefix(prefix: string): void {
    const element = area.value

    if (element === null) {
        return
    }

    const value = props.modelValue
    const start = lineStart(element.selectionStart)
    const after = value.indexOf('\n', element.selectionEnd)
    const end = after === -1 ? value.length : after

    const lines = value.slice(start, end).split('\n')
    const already = lines.every((line) => line.startsWith(prefix))

    const next = lines
        .map((line) => (already ? line.slice(prefix.length) : prefix + line))
        .join('\n')

    replaceRange(start, end, next, start + next.length)
}

/**
 * Put a token either side of the selection, or take it off again.
 *
 * With nothing selected this leaves the cursor between the two halves, so
 * pressing bold and typing does what pressing bold and typing does anywhere
 * else.
 */
function toggleWrap(token: string): void {
    const element = area.value

    if (element === null) {
        return
    }

    const { selectionStart: start, selectionEnd: end } = element
    const selected = props.modelValue.slice(start, end)

    if (
        selected.length >= token.length * 2 &&
        selected.startsWith(token) &&
        selected.endsWith(token)
    ) {
        const inner = selected.slice(token.length, -token.length)

        replaceRange(start, end, inner, start + inner.length)

        return
    }

    const wrapped = token + selected + token

    replaceRange(
        start,
        end,
        wrapped,
        selected === '' ? start + token.length : start + wrapped.length,
    )
}

/**
 * Write a block in, at the start of the line when it belongs there.
 *
 * A line-level block on a line that already has something on it starts a new
 * line instead of being wedged into the middle of a sentence.
 */
function insertBlock(chosen: Block, from?: number): void {
    const element = area.value

    if (element === null) {
        return
    }

    const start = from ?? element.selectionStart
    const end = from === undefined ? element.selectionEnd : element.selectionStart

    if (chosen.upload === true) {
        // The `/image` that was typed goes first, so the picker opening is
        // not followed by the word sitting in the page.
        if (from !== undefined) {
            replaceRange(from, end, '')
        }

        picker.value?.click()

        return
    }

    const onOwnLine = start === lineStart(start)
    const text = (chosen.line === true && !onOwnLine ? '\n' : '') + (chosen.insert ?? '')

    replaceRange(
        start,
        end,
        text,
        chosen.caret === undefined ? undefined : start + text.length - chosen.caret,
    )
}

/*
|------------------------------------------------------------------------------
| The slash menu
|------------------------------------------------------------------------------
*/

/**
 * Open the menu when the line so far is a lone `/` and a word.
 *
 * Recomputed from the text on every input and every cursor move rather than
 * tracked as a state machine, so backspacing over the slash closes it and
 * clicking elsewhere closes it, without either being handled as its own case.
 */
function syncMenu(): void {
    const element = area.value

    if (element === null) {
        return
    }

    const caret = element.selectionStart
    const start = lineStart(caret)
    const found = /^\/([a-z]*)$/i.exec(props.modelValue.slice(start, caret))

    if (found === null) {
        menu.open = false

        return
    }

    menu.open = true
    menu.at = start
    menu.query = found[1]
    menu.index = 0
}

function chooseFromMenu(chosen: Block): void {
    menu.open = false
    insertBlock(chosen, menu.at)
}

function onKeydown(event: KeyboardEvent): void {
    if (!menu.open || matches.value.length === 0) {
        return
    }

    if (event.key === 'Escape') {
        menu.open = false
        event.preventDefault()

        return
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        const step = event.key === 'ArrowDown' ? 1 : -1

        menu.index = (menu.index + step + matches.value.length) % matches.value.length
        event.preventDefault()

        return
    }

    if (event.key === 'Enter' || event.key === 'Tab') {
        const chosen = matches.value[menu.index] ?? matches.value[0]

        chooseFromMenu(chosen)
        event.preventDefault()
    }
}

/*
|------------------------------------------------------------------------------
| Images
|------------------------------------------------------------------------------
*/

/**
 * Send one image and write it into the page where the cursor is.
 *
 * Uploaded one at a time rather than in parallel, because several at once
 * would race to insert themselves at cursor positions that the previous
 * insert has already moved.
 */
async function upload(files: File[]): Promise<void> {
    uploadError.value = null

    for (const file of files) {
        uploading.value += 1

        try {
            const form = new FormData()

            form.append('image', file)

            const payload = await api.post<{ data: { url: string; alt: string } }>(
                props.uploadPath,
                form,
            )

            insertImage(payload.data.alt, payload.data.url)

            // The insert is applied on the next tick, and the one after it
            // reads the cursor this one left behind.
            await nextTick()
        } catch (error) {
            uploadError.value =
                error instanceof ApiError ? error.message : t('admin.wiki.editor.uploadFailed')
        } finally {
            uploading.value -= 1
        }
    }
}

/**
 * Write an image in as a block of its own.
 *
 * The blank lines either side are what make it one. Dropped at the end of a
 * sentence with a single newline, Markdown reads the image as part of that
 * paragraph and renders it inline, mid-text, at whatever size it happens to
 * be -- which is never what somebody dropping a screenshot meant.
 *
 * Only the lines that are missing are added, so dropping an image onto an
 * already-blank line does not push it two paragraphs down the page.
 */
function insertImage(alt: string, url: string): void {
    const element = area.value
    const value = props.modelValue

    const start = element?.selectionStart ?? value.length
    const end = element?.selectionEnd ?? start

    const before = value.slice(0, start)
    const after = value.slice(end)

    const lead =
        before === '' || before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n'
    const tail =
        after === '' ? '\n' : after.startsWith('\n\n') ? '' : after.startsWith('\n') ? '\n' : '\n\n'

    replaceRange(start, end, `${lead}![${alt}](${url})${tail}`)
}

function imagesFrom(list: FileList | null | undefined): File[] {
    return [...(list ?? [])].filter((file) => file.type.startsWith('image/'))
}

function onPaste(event: ClipboardEvent): void {
    const files = imagesFrom(event.clipboardData?.files)

    if (files.length === 0) {
        return
    }

    // Only when there is an image on the clipboard: pasting text must go on
    // being an ordinary paste, undo history and all.
    event.preventDefault()
    void upload(files)
}

function onDrop(event: DragEvent): void {
    dragging.value = false

    const files = imagesFrom(event.dataTransfer?.files)

    if (files.length > 0) {
        void upload(files)
    }
}

function onPicked(event: Event): void {
    const input = event.target as HTMLInputElement

    void upload(imagesFrom(input.files))

    // Cleared so that picking the same file twice in a row fires again.
    input.value = ''
}

/*
|------------------------------------------------------------------------------
| The preview
|------------------------------------------------------------------------------
*/

let timer: ReturnType<typeof setTimeout> | undefined
let inFlight: AbortController | undefined

/**
 * Render the body, a moment after typing stops.
 *
 * Debounced because this is a request per change otherwise, and the in-flight
 * one is abandoned when another starts: two renders of different drafts can
 * come back in either order, and the older one winning would show the page as
 * it was two keystrokes ago.
 */
function refreshPreview(): void {
    clearTimeout(timer)

    // Nothing to render, and nothing the server could say about it.
    if (props.modelValue.trim() === '') {
        inFlight?.abort()
        html.value = ''
        previewFailed.value = false

        return
    }

    timer = setTimeout(async () => {
        inFlight?.abort()
        inFlight = new AbortController()

        try {
            const payload = await request<{ data: { html: string } }>(props.previewPath, {
                method: 'POST',
                body: { body: props.modelValue },
                signal: inFlight.signal,
            })

            html.value = payload.data.html
            previewFailed.value = false
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return
            }

            previewFailed.value = true
        }
    }, 400)
}

watch(() => props.modelValue, refreshPreview, { immediate: true })

onBeforeUnmount(() => {
    clearTimeout(timer)
    inFlight?.abort()
})

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLTextAreaElement).value)
    syncMenu()
}
</script>

<template>
    <div class="markdown-editor">
        <div class="flex flex-wrap items-center gap-1 border-b border-[var(--border-subtle)] pb-2">
            <!--
                The six that get used while writing a sentence. The rest of the
                set is on the slash menu, which is where somebody who wants a
                table is already looking.
            -->
            <button
                type="button"
                class="md-tool"
                :title="t('admin.wiki.editor.blocks.heading')"
                @click="togglePrefix('## ')"
            >
                H2
            </button>
            <button
                type="button"
                class="md-tool font-semibold"
                :title="t('admin.wiki.editor.blocks.bold')"
                @click="toggleWrap('**')"
            >
                B
            </button>
            <button
                type="button"
                class="md-tool italic"
                :title="t('admin.wiki.editor.blocks.italic')"
                @click="toggleWrap('_')"
            >
                I
            </button>
            <button
                type="button"
                class="md-tool"
                :title="t('admin.wiki.editor.blocks.bullets')"
                @click="togglePrefix('- ')"
            >
                &bull;
            </button>
            <button
                type="button"
                class="md-tool"
                :title="t('admin.wiki.editor.blocks.quote')"
                @click="togglePrefix('> ')"
            >
                &rdquo;
            </button>
            <!--
                Drawn rather than set as emoji: an icon that inherits the text
                colour follows a theme, and the emoji for "link" is a different
                picture on every platform.
            -->
            <button
                type="button"
                class="md-tool"
                :title="t('admin.wiki.editor.blocks.link')"
                @click="insertBlock(block('link'))"
            >
                <svg class="size-4" viewBox="0 0 24 24" v-bind="STROKE" aria-hidden="true">
                    <path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7" />
                    <path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7" />
                </svg>
            </button>
            <button
                type="button"
                class="md-tool"
                :title="t('admin.wiki.editor.blocks.image')"
                @click="picker?.click()"
            >
                <svg class="size-4" viewBox="0 0 24 24" v-bind="STROKE" aria-hidden="true">
                    <path
                        d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"
                    />
                    <path d="M8.5 11a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z" />
                    <path d="m21 15-5-5L5 21" />
                </svg>
            </button>

            <span v-if="uploading > 0" class="ml-2 text-[0.8125rem] text-[var(--text-secondary)]">
                {{ t('admin.wiki.editor.uploading') }}
            </span>

            <!--
                Only below the width where both panes fit. Above it there is
                nothing to switch between.
            -->
            <div class="ml-auto flex gap-1 lg:hidden">
                <button
                    type="button"
                    class="md-tool"
                    :class="{ 'md-tool--on': pane === 'write' }"
                    @click="pane = 'write'"
                >
                    {{ t('admin.wiki.editor.write') }}
                </button>
                <button
                    type="button"
                    class="md-tool"
                    :class="{ 'md-tool--on': pane === 'preview' }"
                    @click="pane = 'preview'"
                >
                    {{ t('admin.wiki.editor.preview') }}
                </button>
            </div>
        </div>

        <p v-if="uploadError" class="mt-2 text-[0.8125rem] text-[var(--color-down)]">
            {{ uploadError }}
        </p>

        <div class="mt-2 gap-3 lg:grid lg:grid-cols-2">
            <!--
                The document. Relative because the drop target draws over it,
                and because the slash menu hangs off it.
            -->
            <div
                class="relative"
                :class="{ 'hidden lg:block': pane === 'preview' }"
                @dragenter.prevent="dragging = true"
                @dragover.prevent
                @dragleave="dragging = false"
                @drop.prevent="onDrop"
            >
                <textarea
                    :id="props.inputId"
                    ref="area"
                    :value="props.modelValue"
                    class="field-input font-mono text-[0.8125rem]"
                    :rows="props.rows"
                    @input="onInput"
                    @keydown="onKeydown"
                    @keyup="syncMenu"
                    @click="syncMenu"
                    @blur="menu.open = false"
                    @paste="onPaste"
                ></textarea>

                <div v-if="dragging" class="md-drop">
                    {{ t('admin.wiki.editor.dropHere') }}
                </div>

                <!--
                    The slash menu. On mousedown rather than click, because the
                    textarea's blur would close it first.
                -->
                <ul
                    v-if="menu.open && matches.length > 0"
                    class="md-menu"
                    :aria-label="t('admin.wiki.editor.insert')"
                >
                    <li v-for="(item, index) in matches" :key="item.key">
                        <button
                            type="button"
                            class="md-menu__item"
                            :class="{ 'md-menu__item--on': index === menu.index }"
                            @mousedown.prevent="chooseFromMenu(item)"
                        >
                            {{ t(`admin.wiki.editor.blocks.${item.key}`) }}
                        </button>
                    </li>
                </ul>
            </div>

            <!--
                What readers get, rendered by the renderer that will publish
                it. Same class as the article page, so the preview is the page
                rather than a paler copy of it.
            -->
            <div
                class="min-w-0 overflow-auto rounded-[var(--radius-panel)] border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-4"
                :class="{ 'hidden lg:block': pane === 'write' }"
                :style="{ maxHeight: `${props.rows * 1.45}rem` }"
            >
                <p v-if="previewFailed" class="text-sm text-[var(--color-down)]">
                    {{ t('admin.wiki.editor.previewFailed') }}
                </p>
                <p v-else-if="html === ''" class="text-sm text-[var(--text-muted)]">
                    {{ t('admin.wiki.editor.previewEmpty') }}
                </p>
                <!-- eslint-disable vue/no-v-html -->
                <div v-else class="wiki-prose" v-html="html"></div>
                <!-- eslint-enable vue/no-v-html -->
            </div>
        </div>

        <input
            ref="picker"
            type="file"
            class="sr-only"
            accept="image/png,image/jpeg,image/gif,image/webp"
            multiple
            @change="onPicked"
        />
    </div>
</template>
