<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
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
    <Head title="No access" />
    <SystemMessage
        v-if="variant === 'client'"
        variant="client"
        :icon="Lock"
        title="This link has expired"
        description="Links to your update page expire after a while, for your security. Your agent can send you a new one whenever you need it."
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
        :icon="Lock"
        code="403"
        title="You don’t have access to this page"
        description="A team owner can grant you access in Settings. Until then, your dashboard has everything you can see."
        action-label="Go to dashboard"
        action-href="/dashboard"
    />
</template>
