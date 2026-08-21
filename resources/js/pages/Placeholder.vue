<script setup lang="ts">
/**
 * Scaffolding, not a screen. Slice 0 builds the shell; each destination in
 * the sidebar lands here until the slice that owns it replaces this page.
 */
import { Head } from '@inertiajs/vue3';
import { Hammer } from '@lucide/vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';

const props = defineProps<{ title: string; screen: string; slice: number }>();
</script>

<template>
    <Head :title="props.title" />
    <div class="flex h-full flex-col gap-4 p-6">
        <PageHeader :title="props.title" :subtitle="`${props.screen} · not built yet`" />
        <div class="flex flex-1 rounded-lg border bg-card">
            <EmptyState
                :icon="Hammer"
                :title="`${props.title} arrives in Slice ${props.slice}`"
                :description="`This is the application shell, built in Slice 0. ${props.screen} is built in Slice ${props.slice} — until then this page exists so the navigation can be walked end to end.`"
            />
        </div>
    </div>
</template>
