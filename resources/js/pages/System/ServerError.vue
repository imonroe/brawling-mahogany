<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import SystemMessage from '@/components/app/SystemMessage.vue';

withDefaults(defineProps<{ variant?: 'tenant' | 'admin' | 'client' }>(), {
    variant: 'tenant',
});
</script>

<template>
    <Head title="Something went wrong" />
    <SystemMessage
        v-if="variant === 'client'"
        variant="client"
        :icon="TriangleAlert"
        title="This page isn’t loading right now"
        description="Nothing you did caused it, and nothing has changed on your sale. Try again in a few minutes, or call your agent."
    />
    <SystemMessage
        v-else
        :variant="variant"
        :icon="TriangleAlert"
        code="500"
        title="Something went wrong on our end"
        description="The error has been reported. Try again in a moment — if it keeps happening, tell us what you were doing when it broke."
        action-label="Go to dashboard"
        action-href="/dashboard"
    />
</template>
