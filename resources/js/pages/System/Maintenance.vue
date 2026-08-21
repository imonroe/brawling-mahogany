<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Wrench } from '@lucide/vue';
import SystemMessage from '@/components/app/SystemMessage.vue';

withDefaults(defineProps<{ variant?: 'tenant' | 'admin' | 'client' }>(), {
    variant: 'tenant',
});
</script>

<template>
    <Head title="Back shortly" />
    <SystemMessage
        v-if="variant === 'client'"
        variant="client"
        :icon="Wrench"
        title="We’re updating this page"
        description="It will be back in a few minutes. Nothing has changed on your sale."
    />
    <SystemMessage
        v-else
        :variant="variant"
        :icon="Wrench"
        code="503"
        title="We’re updating the app"
        description="This takes a few minutes. Your work is saved — try again shortly."
    />
</template>
