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
import EmptyState from '@/components/app/EmptyState.vue';
import InvitationLinkPanel from '@/components/app/InvitationLinkPanel.vue';
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
    /**
     * Outstanding invitations (ADR 0003). The console provisions a team *and
     * invites its owner*, and this is the only place that invitation can be
     * seen, replaced, or delivered by hand — which is the whole of onboarding
     * on an install where mail goes nowhere.
     */
    invitations: {
        id: string;
        email: string;
        role: string;
        expiresAt: string;
    }[];
    /** The link just minted, if this render followed a request for one. */
    issuedLink: { id: string; email: string; url: string } | null;
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

/**
 * Mint the accept link for an invitation this console sent.
 *
 * It replaces whatever was emailed, so the confirmation says so — a platform
 * operator invalidating a customer's live link without being told would be a
 * support call, not a feature.
 */
function issueInvitationLink(id: string, email: string): void {
    if (
        !window.confirm(
            `Generate an invitation link for ${email}? Any link already emailed to them stops working.`,
        )
    ) {
        return;
    }

    router.post(`/admin/teams/${props.team.id}/invitations/${id}/link`);
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

        <InvitationLinkPanel v-if="issuedLink" :link="issuedLink" />

        <Card title="Pending invitations">
            <EmptyState
                v-if="invitations.length === 0"
                title="No invitations outstanding"
                description="Anyone invited to this team shows up here until they accept."
            />
            <ul v-else class="flex flex-col">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="min-w-0 flex-1 truncate text-13">{{
                        invitation.email
                    }}</span>
                    <StatusBadge
                        tone="neutral"
                        :label="invitation.role"
                        dotless
                    />
                    <span class="tabular text-[11px] text-muted-foreground"
                        >Expires {{ formatDate(invitation.expiresAt) }}</span
                    >
                    <AppButton
                        variant="ghost"
                        @click="
                            issueInvitationLink(invitation.id, invitation.email)
                        "
                        >Get link</AppButton
                    >
                </li>
            </ul>
        </Card>

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
