<script setup lang="ts">
import { computed, defineAsyncComponent, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useTranslation } from '@/i18n'

const { t } = useTranslation()

/**
 * Skyward — signing in and registering, without leaving the landing page.
 *
 * The forms are the application's own pages, mounted here rather than
 * reimplemented. That is the whole design: a theme may not contain an
 * authentication step, and a second copy of a login form is exactly the kind of
 * thing that drifts out of step with the real one -- it would still be posting
 * last year's fields the day the password policy changed. So the theme decides
 * *where* the form appears and nothing about what it does.
 *
 * `/sign-in` and `/register` keep working as ordinary pages. They have to: a
 * confirmation e-mail links to one, a guest deep-linking lands on one, and a
 * visitor without JavaScript follows the real anchor. The modal is a shortcut
 * over the top of routes that still exist, never a replacement for them.
 *
 * Built on the native dialog element, which brings focus trapping, Escape to
 * dismiss, inertness of the page behind and the top layer for free. Each of
 * those is a thing a hand-rolled overlay gets subtly wrong.
 */
const props = defineProps<{ view: 'sign-in' | 'register' | null }>()
const emit = defineEmits<{ close: [] }>()

/*
 * Async, so neither form is in the landing page's bundle. A visitor who never
 * signs in never downloads them.
 */
const LoginPage = defineAsyncComponent(() => import('@/pages/LoginPage.vue'))
const RegisterPage = defineAsyncComponent(() => import('@/pages/RegisterPage.vue'))

const form = computed(() => (props.view === 'register' ? RegisterPage : LoginPage))

const label = computed(() => (props.view === 'register' ? t('register.title') : t('auth.signIn')))

const dialog = ref<HTMLDialogElement | null>(null)

/*
 * The page behind must not scroll under the dialog. The top layer stops it
 * being interactive but not being scrolled, and a backdrop that slides away
 * from the thing it is dimming looks broken.
 */
function lockScroll(locked: boolean): void {
    document.documentElement.classList.toggle('sky-modal-open', locked)
}

watch(
    () => props.view,
    (view) => {
        const element = dialog.value

        if (element === null) {
            return
        }

        if (view !== null && !element.open) {
            element.showModal()
            lockScroll(true)
        } else if (view === null && element.open) {
            element.close()
            lockScroll(false)
        }
    },
    { flush: 'post' },
)

/*
 * Escape and the close method both fire this, so the parent's state follows the
 * dialog rather than the other way round. Without it, dismissing with Escape
 * would leave the parent believing the modal was still open and the next click
 * on "Log in" would do nothing.
 */
function onClose(): void {
    lockScroll(false)
    emit('close')
}

/*
 * The backdrop is part of the dialog's own box, so a click that lands on the
 * element itself -- rather than on the panel inside it -- is a click outside.
 */
function onBackdrop(event: MouseEvent): void {
    if (event.target === dialog.value) {
        emit('close')
    }
}

/**
 * Moves focus into the form once it has rendered.
 *
 * `showModal()` places focus on the first focusable thing it can find, but the
 * forms are loaded on demand, so the first time one is opened the dialog is
 * still empty when that happens and focus is left outside it. Every later open
 * is fine, because by then the component is cached -- which makes this the kind
 * of bug that is invisible unless the first open is the one being tested.
 *
 * The first field rather than the dialog itself: a login dialog is opened in
 * order to type into it, and this is the case the dialog pattern names as the
 * reason to choose a control over the container.
 */
function focusForm(): void {
    const panel = dialog.value?.querySelector('.sky-modal__panel')
    const target = panel?.querySelector<HTMLElement>(
        'input:not([type="hidden"]), select, textarea, button',
    )

    target?.focus()
}

/* Following a link out of the form leaves the modal with nothing to be about. */
const route = useRoute()
watch(
    () => route.fullPath,
    () => props.view !== null && emit('close'),
)

onBeforeUnmount(() => lockScroll(false))
</script>

<template>
    <dialog
        ref="dialog"
        class="sky-modal"
        :aria-label="label"
        @close="onClose"
        @cancel="onClose"
        @click="onBackdrop"
    >
        <div v-if="props.view" class="sky-modal__panel">
            <button
                type="button"
                class="sky-modal__close"
                :aria-label="t('common.close')"
                @click="emit('close')"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                        d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                    />
                </svg>
            </button>

            <component :is="form" @vue:mounted="focusForm" />
        </div>
    </dialog>
</template>
