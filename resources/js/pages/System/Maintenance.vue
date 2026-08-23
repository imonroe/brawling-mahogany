<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Wrench } from '@lucide/vue';
import SystemMessage from '@/components/app/SystemMessage.vue';

withDefaults(
    defineProps<{
        variant?: 'tenant' | 'admin' | 'client';
        /** IA §9: the client surface always offers a route back to a human. */
        agentName?: string | null;
        agentPhone?: string | null;
    }>(),
    { variant: 'tenant', agentName: null, agentPhone: null },
);
</script>

<template>
    <Head title="Back shortly" />
    <SystemMessage
        v-if="variant === 'client'"
        variant="client"
        :icon="Wrench"
        title="We’re updating this page"
        description="It will be back in a few minutes. Nothing has changed on your sale."
    >
        <a
            v-if="agentPhone"
            class="inline-flex min-h-13 items-center rounded-md bg-brand px-5 text-base font-semibold text-brand-foreground"
            :href="`tel:${agentPhone}`"
            >Call {{ agentName ?? 'your agent' }}</a
        >
    </SystemMessage>
    <SystemMessage
        v-else
        :variant="variant"
        :icon="Wrench"
        code="503"
        title="We’re updating the app"
        description="This takes a few minutes. Your work is saved — try again shortly."
    />
</template>
