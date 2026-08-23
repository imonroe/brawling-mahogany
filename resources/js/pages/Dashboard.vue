<script setup lang="ts">
/**
 * S10, in its empty state.
 *
 * The dashboard's populated composition (stat row, needs-attention panel,
 * active deals, dates, activity) is Slice 2 work, because it needs deals to
 * show. What is built here is the state a new team actually meets first —
 * Design System §15.2 calls it the highest-priority design gap.
 */
import { Head } from '@inertiajs/vue3';
import { Briefcase } from '@lucide/vue';
import AppButton from '@/components/app/AppButton.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
    },
});
</script>

<template>
    <Head title="Dashboard" />
    <div class="flex h-full flex-col gap-6 p-6">
        <PageHeader title="Dashboard" subtitle="No deals yet · nothing due" />
        <div class="flex flex-1 rounded-lg border bg-card">
            <EmptyState
                :icon="Briefcase"
                title="No deals yet"
                description="When a deal is running, this is where the blocked stages, the late tasks, and the next fortnight’s deadlines appear."
            >
                <template #action>
                    <AppButton href="/deals">Create your first deal</AppButton>
                </template>
            </EmptyState>
        </div>
    </div>
</template>
