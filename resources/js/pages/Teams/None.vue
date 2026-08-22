<script setup lang="ts">
/**
 * Signed in, with no live membership anywhere (S09's "no access" state).
 *
 * Reachable rather than a dead end: somebody whose access was revoked, or
 * whose only team is suspended, lands here instead of on a page of empty
 * lists that look like a broken product.
 *
 * ADR 0003 gave it a second job. If an invitation is outstanding for the
 * address this person signs in with, it is shown here and can be accepted
 * with one button — because the alternative was a screen that said "wait for
 * an email" to somebody whose email had already arrived, or was never sent at
 * all.
 */
import { Head, router, usePage } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDate } from '@/lib/formatters';
import type { PendingInvitation } from '@/types';

const props = defineProps<{
    platformHasNoAdministrator?: boolean;
}>();

const page = usePage();

const invitations = computed<PendingInvitation[]>(
    () => (page.props.invitations as PendingInvitation[] | undefined) ?? [],
);

/**
 * Three audiences now, and telling them apart matters.
 *
 * With an invitation waiting, everything else is noise — they are one click
 * from being in, and the screen should say so rather than explain how
 * invitations work.
 *
 * On a running install with none, this is somebody whose access was revoked,
 * or whose only team is suspended; an invitation is genuinely how they get
 * back, and operator instructions would be noise.
 *
 * On a fresh one it is whoever just set the thing up, and "ask for an
 * invitation" is a dead end: teams come from the admin console, the console
 * needs a platform administrator, and nothing in the UI can grant that.
 */
const description = computed(() =>
    props.platformHasNoAdministrator
        ? 'Nobody administers this installation yet, so there are no teams to join. Whoever runs the server grants the first administrator from the command line.'
        : 'Access to a team comes from an invitation. Ask whoever runs your team to send you one — they can email it, or hand you the link directly.',
);

/**
 * One at a time. Accepting spends the invitation and writes to the
 * append-only audit log, so a double-click used to do both twice.
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
        { onFinish: () => (accepting.value = null) },
    );
}

function signOut(): void {
    router.post('/logout');
}
</script>

<template>
    <Head title="No team" />

    <div class="flex min-h-svh items-center justify-center bg-background p-6">
        <div class="flex w-full max-w-md flex-col gap-4">
            <Card v-if="invitations.length > 0" title="Waiting for you">
                <ul class="flex flex-col">
                    <li
                        v-for="invitation in invitations"
                        :key="invitation.id"
                        class="flex flex-wrap items-center gap-3 border-b px-4 py-3 last:border-b-0"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-13 font-medium">{{
                                invitation.teamName
                            }}</span>
                            <span
                                class="tabular truncate text-[11px] text-muted-foreground"
                                >Expires
                                {{ formatDate(invitation.expiresAt) }}</span
                            >
                        </span>
                        <StatusBadge
                            tone="neutral"
                            :label="invitation.role"
                            dotless
                        />
                        <AppButton
                            :disabled="accepting !== null"
                            @click="accept(invitation.id)"
                            >{{
                                accepting === invitation.id
                                    ? 'Accepting…'
                                    : 'Accept'
                            }}</AppButton
                        >
                    </li>
                </ul>
            </Card>

            <div class="rounded-lg border bg-card">
                <EmptyState
                    :icon="Users"
                    :title="
                        invitations.length > 0
                            ? 'Not on a team yet'
                            : 'You’re not on a team yet'
                    "
                    :description="
                        invitations.length > 0
                            ? 'Accept an invitation above and you’re in.'
                            : description
                    "
                >
                    <template #action>
                        <div class="flex flex-col items-center gap-3">
                            <code
                                v-if="
                                    props.platformHasNoAdministrator &&
                                    invitations.length === 0
                                "
                                class="rounded bg-muted px-2.5 py-1.5 text-[11px] text-muted-foreground"
                                >php artisan platform:promote
                                you@example.com</code
                            >
                            <AppButton variant="ghost" @click="signOut"
                                >Sign out</AppButton
                            >
                        </div>
                    </template>
                </EmptyState>
            </div>
        </div>
    </div>
</template>
