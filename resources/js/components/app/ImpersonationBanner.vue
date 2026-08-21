<script setup lang="ts">
/**
 * PRD §4.1 F1.5. A super administrator acting as somebody else must be
 * visually unmistakable — a support session that looks like an ordinary one
 * is how a support session becomes an incident.
 */
import { ShieldAlert } from '@lucide/vue';

defineProps<{ personName: string; returnHref?: string }>();
</script>

<template>
    <div
        class="flex min-h-11 items-center gap-2.5 bg-state-warning px-4 py-2 text-primary-foreground"
        role="status"
        data-slot="impersonation-banner"
    >
        <ShieldAlert
            class="size-4 shrink-0"
            :stroke-width="2"
            aria-hidden="true"
        />
        <p class="flex-1 text-13 font-medium">
            You are viewing the app as {{ personName }}. Everything you do is
            logged.
        </p>
        <a
            v-if="returnHref"
            :href="returnHref"
            class="text-13 font-semibold underline"
            >Stop impersonating</a
        >
    </div>
</template>
