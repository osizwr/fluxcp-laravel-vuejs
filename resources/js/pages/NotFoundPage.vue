<script setup lang="ts">
import AppButton from '../components/ui/AppButton.vue'
import StateBlock from '../components/ui/StateBlock.vue'
</script>

<template>
    <div class="panel mt-6">
        <StateBlock
            variant="empty"
            title="Page not found"
            description="The page you were looking for does not exist."
        >
            <template #action>
                <AppButton variant="primary" size="sm" to="/">Back to status</AppButton>
            </template>
        </StateBlock>
    </div>
</template>
