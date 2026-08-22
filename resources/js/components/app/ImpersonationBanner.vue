<script setup lang="ts">
/**
 * PRD §4.1 F1.5, Screen Inventory S84.
 *
 * A super administrator acting as somebody else must be visually
 * unmistakable — a support session that looks like an ordinary one is how a
 * support session becomes an incident. The reason is shown alongside, because
 * the thing that makes this acceptable is that it explains itself.
 */
import { router } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { formatTime } from '@/lib/formatters';

const props = defineProps<{
    personName: string;
    teamName?: string | null;
    reason?: string | null;
    endsAt?: string | null;
}>();

function stop(): void {
    router.delete('/impersonation');
}
</script>

<template>
    <div
        class="flex min-h-11 flex-wrap items-center gap-x-2.5 gap-y-1 bg-state-warning px-4 py-2 text-primary-foreground"
        role="status"
        data-slot="impersonation-banner"
    >
        <ShieldAlert
            class="size-4 shrink-0"
            :stroke-width="2"
            aria-hidden="true"
        />
        <p class="flex-1 text-13 font-medium">
            You are viewing
            <template v-if="props.teamName">{{ props.teamName }}</template>
            as {{ personName }}. Everything you do is logged.
            <span v-if="props.endsAt" class="font-normal">
                This session ends at {{ formatTime(props.endsAt) }}.
            </span>
        </p>
        <p v-if="props.reason" class="w-full text-[11px] opacity-90">
            Reason: {{ props.reason }}
        </p>
        <button
            type="button"
            class="text-13 font-semibold underline"
            @click="stop"
        >
            Stop impersonating
        </button>
    </div>
</template>
