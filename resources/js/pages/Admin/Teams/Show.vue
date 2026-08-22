<script setup lang="ts">
/**
 * S83 — a team's detail, from outside it.
 *
 * Opening this page writes an audit entry. PRD §9 asks the trail to prove
 * cross-tenant access was *appropriate*, and a trail that records only writes
 * cannot: looking is the thing that needs justifying.
 */
import { Head, router } from '@inertiajs/vue3';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDate } from '@/lib/formatters';

const props = defineProps<{
    team: {
        id: string;
        name: string;
        slug: string;
        timezone: string;
        suspendedAt: string | null;
        purgeAfter: string | null;
        createdAt: string | null;
    };
    usage: { members: number; activeMembers: number };
    members: {
        id: string;
        name: string;
        email: string;
        roles: string[];
        revokedAt: string | null;
    }[];
}>();

function suspend(): void {
    if (
        !window.confirm(
            `Suspend ${props.team.name}? Nobody on the team can sign in until it is restored. Their data is untouched.`,
        )
    ) {
        return;
    }

    router.post(`/admin/teams/${props.team.id}/suspend`);
}

function restore(): void {
    router.post(`/admin/teams/${props.team.id}/restore`);
}
</script>

<template>
    <Head :title="team.name" />

    <div class="flex flex-col gap-4 p-6">
        <PageHeader :title="team.name" :subtitle="team.slug">
            <template #actions>
                <AppButton
                    variant="ghost"
                    :href="`/admin/teams/${team.id}/impersonate`"
                    >Impersonate</AppButton
                >
                <AppButton
                    v-if="team.suspendedAt"
                    variant="ghost"
                    @click="restore"
                    >Restore</AppButton
                >
                <AppButton v-else variant="warning" @click="suspend"
                    >Suspend</AppButton
                >
            </template>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-3">
            <Card title="Members">
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ usage.members }}
                </p>
            </Card>
            <Card title="With access">
                <p class="tabular px-4 py-4 text-2xl font-semibold">
                    {{ usage.activeMembers }}
                </p>
            </Card>
            <Card title="Timezone">
                <p class="px-4 py-4 text-13">{{ team.timezone }}</p>
            </Card>
        </div>

        <Card title="Team access">
            <ul class="flex flex-col">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-13 font-medium">{{
                            member.name
                        }}</span>
                        <span
                            class="truncate text-[11px] text-muted-foreground"
                            >{{ member.email }}</span
                        >
                    </span>
                    <StatusBadge
                        v-for="role in member.roles"
                        :key="role"
                        tone="neutral"
                        :label="role"
                        dotless
                    />
                    <StatusBadge
                        v-if="member.revokedAt"
                        tone="danger"
                        label="Revoked"
                        dotless
                    />
                </li>
            </ul>
        </Card>

        <p v-if="team.createdAt" class="text-[11px] text-muted-foreground">
            Provisioned {{ formatDate(team.createdAt) }}.
        </p>
    </div>
</template>
