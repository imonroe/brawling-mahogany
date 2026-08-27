<script setup lang="ts">
/**
 * S05's 429, and the reason it needs a page rather than a status code (#110).
 *
 * A client on the status page has no session, no account and nothing to retry
 * with, so IA §9's rule — *"a refusal is a page, not a status code"* — applies
 * here exactly as it does to an expired link. Symfony's own "Too Many
 * Requests" body is a white page with a code on it, which for this reader is
 * indistinguishable from the product being broken.
 *
 * The client wording says nothing about limits or requests, because neither is
 * a thing they did: from where they are standing they pressed a link twice.
 */
import { Head } from '@inertiajs/vue3';
import { Hourglass } from '@lucide/vue';
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
    <Head title="One moment" />
    <SystemMessage
        v-if="variant === 'client'"
        variant="client"
        :icon="Hourglass"
        title="Give it a minute"
        description="You’ve opened this a few times in quick succession. Wait a minute and try the link again — nothing has changed on your sale."
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
        :icon="Hourglass"
        code="429"
        title="Too many attempts"
        description="Wait a minute and try again. Nothing has been lost."
    />
</template>
