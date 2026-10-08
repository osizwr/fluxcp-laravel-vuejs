<script setup lang="ts">
import AppButton from '../components/ui/AppButton.vue'
import StateBlock from '../components/ui/StateBlock.vue'
import { useTranslation } from '../i18n'

const { t } = useTranslation()
</script>

<template>
    <div class="panel mt-6">
        <StateBlock
            variant="empty"
            :title="t('notFound.title')"
            description="The page you were looking for does not exist."
        >
            <template #action>
                <AppButton variant="primary" size="sm" to="/">{{ t('notFound.back') }}</AppButton>
            </template>
        </StateBlock>
    </div>
</template>
