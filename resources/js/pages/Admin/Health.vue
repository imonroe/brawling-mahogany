<script setup lang="ts">
/**
 * S85 — system health.
 *
 * The operator's one screen for "is anything on fire".
 *
 * The panels whose slice has not shipped say so, in words. A zero next to
 * "Bounce rate" reads as perfect deliverability and is actually no SES
 * integration — which is the difference between a health screen and a
 * decoration (issue #54).
 */
import { Head } from '@inertiajs/vue3';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';

defineProps<{
    queue: {
        available: boolean;
        pending: number | null;
        failed: number;
        supervisors: number | null;
    };
    panels: { key: string; label: string; target: string; slice: number }[];
}>();
</script>

<template>
    <Head title="System health" />

    <div class="flex flex-col gap-4 p-6">
        <PageHeader
            title="System health"
            subtitle="Queues, sending, and spend"
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <Card title="Queue depth">
                <template #badge>
                    <StatusBadge
                        v-if="!queue.available"
                        tone="warning"
                        label="Reporter unreachable"
                        dotless
                    />
                </template>
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ queue.pending ?? '—' }}
                </p>
                <p
                    v-if="!queue.available"
                    class="px-4 pb-3 text-[11px] text-muted-foreground"
                >
                    Horizon needs Redis, and it isn’t answering. Failed jobs
                    below come from the database and are still accurate.
                </p>
            </Card>

            <Card title="Failed jobs">
                <template #badge>
                    <StatusBadge
                        v-if="queue.failed > 0"
                        tone="danger"
                        :label="`${queue.failed}`"
                        dotless
                    />
                </template>
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ queue.failed }}
                </p>
            </Card>

            <Card title="Workers">
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ queue.supervisors ?? '—' }}
                </p>
            </Card>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card v-for="panel in panels" :key="panel.key" :title="panel.label">
                <div class="flex flex-col gap-1 px-4 py-4">
                    <p class="text-13 text-muted-foreground">
                        Nothing to report — this ships in slice
                        {{ panel.slice }}.
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        Target: {{ panel.target }}
                    </p>
                </div>
            </Card>
        </div>
    </div>
</template>
