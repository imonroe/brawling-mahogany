<script setup lang="ts">
/**
 * S13 — the deals index, which is #78 and not built yet.
 *
 * A placeholder with one working control, because PRD §5.2 step 1 is *"Heather
 * clicks New Deal"* and the wizard behind it is real. A nav item pointing at a
 * screen with no way into the thing it is about would make S14 unreachable
 * except by typing the URL.
 */
import { Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppButton from '@/components/app/AppButton.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';

defineProps<{ screen: string; slice: number }>();
</script>

<template>
    <Head title="Deals" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader title="Deals">
            <template #actions>
                <AppButton href="/deals/create">
                    <Plus class="size-4" aria-hidden="true" />
                    New deal
                </AppButton>
            </template>
        </PageHeader>

        <EmptyState
            :title="`The deals list lands with slice ${slice}`"
            :description="`${screen} is still to build. Creating a deal works now — the list of them arrives with the deal views.`"
            class="rounded-lg border bg-card"
        >
            <template #action>
                <AppButton href="/deals/create">New deal</AppButton>
            </template>
        </EmptyState>
    </div>
</template>
