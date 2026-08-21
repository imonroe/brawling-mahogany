<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import SystemMessage from '@/components/app/SystemMessage.vue';

withDefaults(defineProps<{ variant?: 'tenant' | 'admin' | 'client' }>(), {
    variant: 'tenant',
});
</script>

<template>
    <Head title="No access" />
    <SystemMessage
        v-if="variant === 'client'"
        variant="client"
        :icon="Lock"
        title="This link has expired"
        description="Links to your update page expire after a while, for your security. Your agent can send you a new one whenever you need it."
    />
    <SystemMessage
        v-else
        :variant="variant"
        :icon="Lock"
        code="403"
        title="You don’t have access to this page"
        description="A team owner can grant you access in Settings. Until then, your dashboard has everything you can see."
        action-label="Go to dashboard"
        action-href="/dashboard"
    />
</template>
