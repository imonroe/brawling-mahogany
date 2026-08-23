<script setup lang="ts">
/**
 * S81 — the super admin dashboard.
 *
 * Counts and an audit tail, deliberately. Every read here crosses a tenant
 * boundary, so the screen shows how many rather than which: the operator's job
 * is knowing whether the platform is healthy, not reading somebody's client
 * list. When they do need to look at a team, S83 is the door, and it writes an
 * audit entry on the way through.
 */
import { Head, Link } from '@inertiajs/vue3';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { formatDate, formatTime } from '@/lib/formatters';

defineProps<{
    teamCount: number;
    suspendedCount: number;
    personCount: number;
    membershipCount: number;
    recentAudit: {
        id: string;
        action: string;
        teamId: string | null;
        createdAt: string;
    }[];
}>();
</script>

<template>
    <Head title="Super admin" />

    <div class="flex flex-col gap-4 p-6">
        <PageHeader title="Platform" subtitle="Every tenant, at a glance" />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card title="Teams">
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ teamCount }}
                </p>
            </Card>
            <Card title="Suspended">
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ suspendedCount }}
                </p>
            </Card>
            <Card title="People">
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ personCount }}
                </p>
            </Card>
            <Card title="Memberships">
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ membershipCount }}
                </p>
            </Card>
        </div>

        <Card title="Recent audit entries">
            <template #action>
                <Link
                    href="/admin/audit"
                    class="text-xs font-medium text-primary"
                    >See all</Link
                >
            </template>
            <ul class="flex flex-col">
                <li
                    v-for="entry in recentAudit"
                    :key="entry.id"
                    class="flex min-h-11 items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="min-w-0 flex-1 truncate text-13">{{
                        entry.action
                    }}</span>
                    <span class="tabular text-[11px] text-muted-foreground"
                        >{{ formatDate(entry.createdAt) }},
                        {{ formatTime(entry.createdAt) }}</span
                    >
                </li>
            </ul>
        </Card>
    </div>
</template>
