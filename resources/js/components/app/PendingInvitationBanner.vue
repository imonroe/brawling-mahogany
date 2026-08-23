<script setup lang="ts">
/**
 * An invitation waiting for whoever is signed in (ADR 0003 · S09).
 *
 * Somebody already inside a team never visits S09, so without this the second
 * invitation they receive is visible only in an email — and an email is
 * exactly the channel ADR 0003 says no flow may depend on. The shell is the
 * one surface everybody sees, so this is where it goes.
 *
 * Deliberately informational rather than a warning: nothing is wrong, and
 * `bg-state-warning` is spoken for by the impersonation banner, which *is*.
 */
import { router } from '@inertiajs/vue3';
import { MailPlus } from '@lucide/vue';
import { ref } from 'vue';
import type { PendingInvitation } from '@/types';

const props = defineProps<{
    invitations: PendingInvitation[];
}>();

/**
 * Accepting is not idempotent: each post spends the invitation and writes an
 * `invitation.accepted` row into a log whose whole point is that it is
 * append-only. Two rapid clicks used to produce two of them.
 */
const accepting = ref<string | null>(null);

function accept(id: string): void {
    if (accepting.value !== null) {
        return;
    }

    accepting.value = id;

    router.post(
        `/invitations/${id}/claim`,
        {},
        {
            onFinish: () => (accepting.value = null),
        },
    );
}
</script>

<template>
    <div
        v-for="invitation in props.invitations"
        :key="invitation.id"
        class="flex min-h-11 flex-wrap items-center gap-x-2.5 gap-y-1 border-b bg-muted px-4 py-2 text-foreground"
        role="status"
        data-slot="pending-invitation-banner"
    >
        <MailPlus
            class="size-4 shrink-0"
            :stroke-width="2"
            aria-hidden="true"
        />
        <p class="flex-1 text-13">
            You’ve been invited to
            <span class="font-medium">{{ invitation.teamName }}</span>
            as {{ invitation.role }}.
        </p>
        <button
            type="button"
            class="text-13 font-semibold underline disabled:opacity-60"
            :disabled="accepting !== null"
            @click="accept(invitation.id)"
        >
            {{ accepting === invitation.id ? 'Accepting…' : 'Accept' }}
        </button>
    </div>
</template>
