<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AlertMessage from './AlertMessage.vue'
import AppButton from './AppButton.vue'
import CodeInput from './CodeInput.vue'
import { useAccounts } from '../../composables/useAccounts'
import { ApiError, api } from '../../services/api'
import { useTranslation } from '../../i18n'

/**
 * Entering the code from the confirmation e-mail.
 *
 * Shown in two places, which is why it is a component rather than markup in
 * the registration page: straight after registering, and again when somebody
 * tries to sign in to an account that is still waiting. Both are the same
 * question, and a visitor who closed the tab should meet the same form rather
 * than a dead end telling them to check their e-mail.
 *
 * The e-mailed link still works and does the same thing; this is the other
 * half of the same confirmation, not a replacement for it.
 */
const props = withDefaults(
    defineProps<{
        username: string
        /** Only known straight after registering, and only used to say where it went. */
        email?: string
        /**
         * Whether a code has already gone out.
         *
         * False when the registration mail failed, and the point of saying so
         * is that there is then nothing to wait for: the cooldown below would
         * otherwise sit a visitor in front of a timer counting down to a resend
         * of a message that never arrived in the first place.
         */
        codeSent?: boolean
    }>(),
    { codeSent: true },
)

const emit = defineEmits<{ confirmed: [] }>()

const { t } = useTranslation()

/*
 * How many boxes to draw. Published by the server from the class that
 * generates the codes, rather than written as a 6 here where a change to the
 * generator would not reach it.
 */
const { config } = useAccounts()
const length = computed(() => config.value.confirmationCodeLength)

/**
 * How long the resend control stays shut after a code goes out.
 *
 * Short enough not to strand somebody whose mail is slow, long enough that the
 * first instinct is to go and look in the inbox. It is also well inside the
 * server's own limit -- three resends per account per fifteen minutes -- so the
 * countdown spaces requests out rather than standing in for that limit. When
 * the server does refuse, it says how long for and that message is shown as it
 * came; this timer never speaks for it.
 */
const COOLDOWN_SECONDS = 60

/**
 * How long a rejected code stays red before the row empties itself.
 *
 * Long enough to be seen as an answer to what was just typed, short enough not
 * to be a wait. The row clears at the end of it rather than at the start: the
 * red has to land on the digits it is about to throw away, or it reads as a
 * complaint about an empty row.
 */
const REJECTION_MS = 900

const code = ref('')
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const submitting = ref(false)
const resending = ref(false)

/*
 * The red, which is deliberately not `error !== null`.
 *
 * The message stays up until the next attempt, because it is the only thing
 * that explains why the row emptied. The colour is an answer to one particular
 * code and goes once that code is gone -- leaving it on would colour the
 * digits typed next, before anything has judged them.
 */
const rejected = ref(false)
let rejection: ReturnType<typeof setTimeout> | undefined

/** Shut while the code is in flight and while the rejection is on screen. */
const locked = computed(() => submitting.value || rejected.value)

const remaining = ref(0)
let ticker: ReturnType<typeof setInterval> | undefined

const codeInput = ref<InstanceType<typeof CodeInput> | null>(null)

/*
 * The address a resend is sent to, which the form never asks for.
 *
 * Straight after registering it is already known, and it is sent so the server
 * can match it the way it does for any other resend.
 *
 * On the way in from sign-in it is not known -- all the form has is the
 * account name -- and it is not asked for either. Reaching this screen that
 * way means the password was accepted and the confirmation was the only thing
 * in the way, so the server already knows whose account it is and sends to the
 * address the registration used. Asking would only make somebody recall, on
 * the spot, which of their addresses they signed up with.
 */
const canResend = computed(() => !resending.value)

/** `m:ss`, so a minute reads as a minute rather than as 60. */
const countdown = computed(() => {
    const minutes = Math.floor(remaining.value / 60)
    const seconds = remaining.value % 60

    return `${minutes}:${String(seconds).padStart(2, '0')}`
})

function startCooldown(): void {
    remaining.value = COOLDOWN_SECONDS

    clearInterval(ticker)
    ticker = setInterval(() => {
        remaining.value -= 1

        if (remaining.value <= 0) {
            clearInterval(ticker)
            ticker = undefined
        }
    }, 1000)
}

onMounted(() => {
    // A code went out moments ago as part of registering, so the wait starts
    // from that send rather than from the first time the button is looked at.
    if (props.codeSent && props.email !== undefined && props.email !== '') {
        startCooldown()
    }
})

function cancelRejection(): void {
    clearTimeout(rejection)
    rejection = undefined
    rejected.value = false
}

/** Colour the code that failed, then take it away and hand the row back. */
function reject(): void {
    clearTimeout(rejection)
    rejected.value = true

    rejection = setTimeout(() => {
        rejection = undefined
        rejected.value = false
        code.value = ''
        codeInput.value?.focus()
    }, REJECTION_MS)
}

onBeforeUnmount(() => {
    clearInterval(ticker)
    clearTimeout(rejection)
})

/*
 * Editing the code retracts the verdict on the previous one: leaving the row
 * red while it is being corrected says the digits now in it are wrong.
 *
 * Filling the last box confirms, which is why there is no button to press. A
 * code is either complete or it is not, and a complete one leaves nothing to
 * decide -- so asking for a click afterwards only adds a step. Each attempt
 * still costs an edit: a rejected code sits there until a digit is changed,
 * and that change is what asks again.
 */
watch(code, (value) => {
    /*
     * Only a real keystroke retracts the message. The reset above empties the
     * row itself, and clearing on that would take away the one thing saying
     * why it emptied.
     */
    if (value !== '') {
        error.value = null
    }

    if (value.length === length.value) {
        void submit()
    }
})

async function submit(): Promise<void> {
    if (submitting.value || code.value.length < length.value) {
        return
    }

    submitting.value = true
    error.value = null
    notice.value = null

    try {
        await api.post('auth/confirm', { username: props.username, code: code.value })
        emit('confirmed')
    } catch (problem) {
        /*
         * One message for every way a code can fail -- wrong, expired, or
         * guessed at too often. The server does not distinguish them either,
         * and telling somebody probing which case they are in is the whole
         * reason not to.
         */
        error.value =
            problem instanceof ApiError && problem.isValidation
                ? t('register.otpWrong')
                : t('common.genericError')
    } finally {
        submitting.value = false
    }

    if (error.value !== null) {
        reject()
    }
}

async function resend(): Promise<void> {
    if (!canResend.value || remaining.value > 0) {
        return
    }

    resending.value = true
    error.value = null
    notice.value = null
    cancelRejection()

    try {
        await api.post('auth/confirm/resend', {
            username: props.username,
            ...(props.email !== undefined && props.email !== '' ? { email: props.email } : {}),
        })
        notice.value = t('register.otpResent')
        code.value = ''
        startCooldown()
    } catch (problem) {
        /*
         * A refusal from the server's own limit carries the real wait in its
         * message, so it is shown as it came rather than restated as another
         * countdown -- and the cooldown is deliberately not restarted, because
         * nothing was sent.
         */
        error.value =
            problem instanceof ApiError && (problem.isRateLimited || problem.isValidation)
                ? problem.message
                : t('common.genericError')
    } finally {
        resending.value = false
    }
}
</script>

<template>
    <div>
        <h1 class="text-xl font-semibold tracking-tight">{{ t('register.otpTitle') }}</h1>

        <p class="mt-1 mb-4 text-sm text-[var(--text-secondary)]">
            {{
                props.email
                    ? t('register.otpBody', { email: props.email })
                    : t('register.otpPending')
            }}
        </p>

        <AlertMessage v-if="error" tone="error" class="mb-4">{{ error }}</AlertMessage>
        <AlertMessage v-if="notice" tone="success" class="mb-4">{{ notice }}</AlertMessage>

        <form class="panel space-y-4 p-4" novalidate @submit.prevent="submit">
            <fieldset>
                <!--
                    A legend rather than a label, because the control is six
                    inputs and a label can only point at one of them. Each box
                    carries its own position in an aria-label; this names the
                    group they belong to.
                -->
                <legend class="mb-2 block w-full text-center text-sm font-medium">
                    {{ t('register.otpLabel') }}
                </legend>

                <CodeInput
                    ref="codeInput"
                    v-model="code"
                    :length="length"
                    :invalid="rejected"
                    :disabled="locked"
                />
            </fieldset>

            <!--
                Something has to say the code went off, now that filling the
                last box is the whole of the submission and there is no button
                left to carry a spinner.
            -->
            <p
                v-if="submitting"
                class="text-center text-sm text-[var(--text-secondary)]"
                role="status"
            >
                {{ t('confirm.confirmingAccount') }}
            </p>

            <div class="space-y-3">
                <p class="text-center text-sm text-[var(--text-secondary)]">
                    {{ t('register.otpDidntReceive') }}
                </p>

                <!--
                    The countdown is the button's own label while it is shut,
                    rather than text beside a greyed-out control: a disabled
                    button with a timer next to it asks to be clicked, and
                    clicking it does nothing.
                -->
                <AppButton
                    block
                    :loading="resending"
                    :disabled="!canResend || remaining > 0"
                    @click="resend"
                >
                    <span :class="remaining > 0 ? 'tabular-nums' : undefined">
                        {{
                            remaining > 0
                                ? t('register.otpResendIn', { time: countdown })
                                : t('register.otpResend')
                        }}
                    </span>
                </AppButton>
            </div>
        </form>
    </div>
</template>
