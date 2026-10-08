<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAccounts } from '../../composables/useAccounts'
import FormField from './FormField.vue'
import { useTranslation } from '../../i18n'

const { t } = useTranslation()

/**
 * The security check on a public form.
 *
 * Renders nothing when the operator has it turned off, so a page can include
 * it unconditionally.
 *
 * Only the self-hosted challenge is drawn here. With reCAPTCHA configured the
 * widget comes from Google and would need its script loaded, which this panel
 * does not do on its own account -- so the field explains that instead of
 * rendering a box that cannot be answered. That is a deliberate limit, stated
 * rather than hidden: see docs/MIGRATION_DECISIONS.md (D19).
 */
const model = defineModel<string>({ required: true })

const props = defineProps<{ error?: string }>()

const { captchaOnRegistration, captchaSelfHosted } = useAccounts()

/**
 * Appended to the image URL to defeat caching.
 *
 * The response already carries no-store, but a reload has to request a new
 * challenge rather than re-display the one the browser has in memory.
 */
const nonce = ref(0)
const imageUrl = ref('')

function refresh(): void {
    nonce.value += 1
    imageUrl.value = `/api/captcha?v=${nonce.value}`
}

onMounted(() => {
    if (captchaOnRegistration.value && captchaSelfHosted.value) {
        refresh()
    }
})

defineExpose({ refresh })
</script>

<template>
    <div v-if="captchaOnRegistration">
        <template v-if="captchaSelfHosted">
            <FormField :label="t('captcha.label')" :error="props.error">
                <template #default="{ id, invalid, describedBy }">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <img
                            v-if="imageUrl"
                            :src="imageUrl"
                            width="200"
                            height="70"
                            class="rounded-[var(--radius-control)] border border-[var(--border-strong)] bg-white"
                            :alt="t('captcha.alt')"
                        />

                        <button
                            type="button"
                            class="text-sm text-[var(--text-secondary)] underline underline-offset-2 hover:text-[var(--text-primary)]"
                            @click="refresh"
                        >
                            {{ t('captcha.newImage') }}
                        </button>
                    </div>

                    <input
                        :id="id"
                        v-model.trim="model"
                        class="field-input mt-2"
                        type="text"
                        name="captcha"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        maxlength="16"
                        required
                        :aria-invalid="invalid || undefined"
                        :aria-describedby="describedBy"
                    />
                </template>
            </FormField>
        </template>

        <p v-else class="text-sm text-[var(--color-warn)]">
            This form requires a security check that this panel cannot display. Ask the
            administrator to set PANEL_CAPTCHA_DRIVER=native.
        </p>
    </div>
</template>
