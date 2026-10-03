import { reactive, ref, type Ref } from 'vue'
import { ApiError } from '../services/api'

export interface FormState {
    /** Field-keyed messages, for showing beside the input that caused them. */
    errors: Record<string, string>
    /** A message with no field to attach it to. */
    error: Ref<string | null>
    /** A message to show on success, when the endpoint returned one. */
    success: Ref<string | null>
    submitting: Ref<boolean>
    submit: (action: () => Promise<unknown>) => Promise<boolean>
    reset: () => void
}

/**
 * The submit-and-show-what-went-wrong half of a form.
 *
 * Every account form needs the same four things: clear the previous errors,
 * disable the button, map Laravel's field-keyed 422 body onto the inputs, and
 * fall back to a general message for anything else. Written once here because
 * the version that gets forgotten is always the last branch -- the one that
 * turns an unexpected 500 into a form that silently does nothing.
 */
export function useFormSubmit(): FormState {
    const errors = reactive<Record<string, string>>({})
    const error = ref<string | null>(null)
    const success = ref<string | null>(null)
    const submitting = ref(false)

    function reset(): void {
        for (const key of Object.keys(errors)) {
            delete errors[key]
        }

        error.value = null
        success.value = null
    }

    async function submit(action: () => Promise<unknown>): Promise<boolean> {
        if (submitting.value) {
            return false
        }

        submitting.value = true
        reset()

        try {
            await action()

            return true
        } catch (caught) {
            if (caught instanceof ApiError) {
                for (const [field, messages] of Object.entries(caught.errors)) {
                    if (messages[0] !== undefined) {
                        errors[field] = messages[0]
                    }
                }

                /*
                 * A 422 with no field-keyed errors is how the account
                 * endpoints report "that link is not valid": the message is
                 * the whole answer, and there is no input to blame.
                 */
                if (Object.keys(caught.errors).length === 0) {
                    error.value = caught.message
                }
            } else {
                error.value = 'Something went wrong. Please try again.'
            }

            return false
        } finally {
            submitting.value = false
        }
    }

    return { errors, error, success, submitting, submit, reset }
}
