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
import AppButton from '@/components/app/AppButton.vue';
import EmptyState from '@/components/app/EmptyState.vue';

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
                description="Access to a team comes from an invitation. Ask whoever runs your team to send you one, and the link in that email will bring you straight here."
            >
                <template #action>
                    <AppButton variant="ghost" @click="signOut"
                        >Sign out</AppButton
                    >
                </template>
            </EmptyState>
        </div>
    </div>
</template>
