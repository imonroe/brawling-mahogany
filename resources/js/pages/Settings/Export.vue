<script setup lang="ts">
/**
 * S79 — team data export.
 *
 * PRD §9: a team can export its own data. Queued, because a team with 500 past
 * clients is not exporting in a web request, and delivered as a **signed,
 * expiring** download rather than a link that works forever.
 *
 * Documents are exported as metadata and a manifest, not as files. That was
 * left open in issue #56 as a size and liability question, and it is settled
 * here: an archive containing every uploaded inspection report is a second
 * copy of the riskiest data the product holds, sitting behind a link.
 */
import { Head, router } from '@inertiajs/vue3';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import Heading from '@/components/app/Heading.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDate, formatTime } from '@/lib/formatters';

defineProps<{
    exports: {
        id: string;
        state: string;
        stateLabel: string;
        requestedAt: string | null;
        sizeBytes: number | null;
        expiresAt: string | null;
        downloadUrl: string | null;
    }[];
}>();

function request(): void {
    router.post('/settings/export');
}
</script>

<template>
    <Head title="Export" />

    <div class="flex flex-col gap-4">
        <Heading
            title="Export"
            description="A copy of your team’s people and activity, as JSON."
        />

        <Card title="Your exports">
            <template #action>
                <AppButton @click="request">Request an export</AppButton>
            </template>

            <EmptyState
                v-if="exports.length === 0"
                title="Nothing exported yet"
                description="Request one and we’ll prepare it in the background. You’ll get a link that works for two days."
            >
                <template #action>
                    <AppButton @click="request">Request an export</AppButton>
                </template>
            </EmptyState>

            <ul v-else class="flex flex-col">
                <li
                    v-for="entry in exports"
                    :key="entry.id"
                    class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="tabular min-w-0 flex-1 text-13">
                        {{
                            entry.requestedAt
                                ? `${formatDate(entry.requestedAt)}, ${formatTime(entry.requestedAt)}`
                                : '—'
                        }}
                    </span>
                    <StatusBadge
                        tone="neutral"
                        :label="entry.stateLabel"
                        dotless
                    />
                    <span
                        v-if="entry.expiresAt && entry.downloadUrl"
                        class="tabular text-[11px] text-muted-foreground"
                        >Link expires {{ formatDate(entry.expiresAt) }}</span
                    >
                    <a
                        v-if="entry.downloadUrl"
                        :href="entry.downloadUrl"
                        class="text-13 font-semibold text-primary underline"
                        >Download</a
                    >
                </li>
            </ul>
        </Card>
    </div>
</template>
