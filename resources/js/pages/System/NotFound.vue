<script setup lang="ts">
import { FileQuestionMark } from '@lucide/vue';
import { Head } from '@inertiajs/vue3';
import SystemMessage from '@/components/app/SystemMessage.vue';

withDefaults(defineProps<{ variant?: 'tenant' | 'admin' | 'client' }>(), { variant: 'tenant' });
</script>

<template>
    <Head title="Page not found" />
    <SystemMessage
        v-if="variant === 'client'"
        variant="client"
        :icon="FileQuestionMark"
        title="We couldn’t find that page"
        description="The link may be out of date. Your agent can send you a current one whenever you need it."
    />
    <SystemMessage
        v-else
        :variant="variant"
        :icon="FileQuestionMark"
        code="404"
        title="We couldn’t find that page"
        description="It may have been deleted, or the link may be wrong. Check the address, or start again from your dashboard."
        action-label="Go to dashboard"
        action-href="/dashboard"
    />
</template>
