<script setup lang="ts">
/**
 * Signed in, with no live membership anywhere (S09's "no access" state).
 *
 * Reachable rather than a dead end: somebody whose access was revoked, or
 * whose only team is suspended, lands here instead of on a page of empty
 * lists that look like a broken product.
 */
import { Head, router } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import EmptyState from '@/components/app/EmptyState.vue';

const props = defineProps<{
    platformHasNoAdministrator?: boolean;
}>();

/**
 * Two audiences, and telling them apart matters.
 *
 * On a running install this is somebody whose access was revoked, or whose
 * only team is suspended — an invitation is genuinely how they get back, and
 * operator instructions would be noise.
 *
 * On a fresh one it is whoever just set the thing up, and "ask for an
 * invitation" is a dead end: teams come from the admin console, the console
 * needs a platform administrator, and nothing in the UI can grant that.
 */
const description = computed(() =>
    props.platformHasNoAdministrator
        ? 'Nobody administers this installation yet, so there are no teams to join. Whoever runs the server grants the first administrator from the command line.'
        : 'Access to a team comes from an invitation. Ask whoever runs your team to send you one, and the link in that email will bring you straight here.',
);

function signOut(): void {
    router.post('/logout');
}
</script>

<template>
    <Head title="No team" />

    <div class="flex min-h-svh items-center justify-center bg-background p-6">
        <div class="w-full max-w-md rounded-lg border bg-card">
            <EmptyState
                :icon="Users"
                title="You’re not on a team yet"
                :description="description"
            >
                <template #action>
                    <div class="flex flex-col items-center gap-3">
                        <code
                            v-if="props.platformHasNoAdministrator"
                            class="rounded bg-muted px-2.5 py-1.5 text-[11px] text-muted-foreground"
                            >php artisan platform:promote you@example.com</code
                        >
                        <AppButton variant="ghost" @click="signOut"
                            >Sign out</AppButton
                        >
                    </div>
                </template>
            </EmptyState>
        </div>
    </div>
</template>
