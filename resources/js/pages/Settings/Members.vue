<script setup lang="ts">
/**
 * S74 — members and invitations.
 *
 * The rule worth naming: **a team must always keep one Team Owner who can
 * sign in.** Removing the last one is refused by the server with copy that
 * explains why, and the reason is shown here rather than as a generic
 * validation message — a team with no owner cannot invite anybody, change its
 * settings, or recover without the platform operator.
 */
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import { computed } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import Heading from '@/components/app/Heading.vue';
import InvitationLinkPanel from '@/components/app/InvitationLinkPanel.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/formatters';

defineProps<{
    members: {
        id: string;
        name: string;
        email: string;
        hasLogin: boolean;
        roles: string[];
        revokedAt: string | null;
    }[];
    invitations: {
        id: string;
        email: string;
        role: string;
        expiresAt: string;
    }[];
    assignableRoles: {
        id: string;
        key: string;
        name: string;
        description: string | null;
    }[];
    /**
     * The link the server just minted, if this render followed a request for
     * one (ADR 0003). Null on every other visit, and deliberately so — see
     * `InvitationLinkPanel`.
     */
    issuedLink: { id: string; email: string; url: string } | null;
}>();

const page = usePage();

/*
 * The last-owner refusal comes back from the revoke endpoint rather than the
 * invite form, so it is read off the page's shared errors. It gets its own
 * alert because the copy is the point: a generic "validation failed" would
 * leave somebody re-clicking the button (issue #45).
 */
const lastOwnerError = computed(
    () => (page.props.errors as Record<string, string>)?.membership ?? null,
);

const invite = useForm({
    email: '',
    first_name: '',
    last_name: '',
    role_id: '',
});

function submit(): void {
    invite.post('/settings/members/invitations', {
        preserveScroll: true,
        onSuccess: () => invite.reset(),
    });
}

function revoke(id: string, name: string): void {
    if (
        !window.confirm(
            `Revoke ${name}’s access? Their name stays on everything they’ve already done — this only stops them signing in.`,
        )
    ) {
        return;
    }

    router.delete(`/settings/members/${id}`, { preserveScroll: true });
}

/**
 * Ask for the accept link instead of relying on the message (ADR 0003).
 *
 * The honest framing matters here: this *replaces* whatever was emailed, so
 * the confirmation says so before anybody invalidates a link a colleague is
 * about to click.
 */
function issueLink(id: string, email: string): void {
    if (
        !window.confirm(
            `Generate an invitation link for ${email}? Any link already emailed to them stops working.`,
        )
    ) {
        return;
    }

    router.post(
        `/settings/members/invitations/${id}/link`,
        {},
        { preserveScroll: true },
    );
}

function revokeInvitation(id: string): void {
    router.delete(`/settings/members/invitations/${id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Members" />

    <div class="flex flex-col gap-4">
        <Heading
            title="Members"
            description="Who can sign in to this team, and what they can do."
        />

        <Alert v-if="lastOwnerError" variant="destructive">
            <AlertDescription>{{ lastOwnerError }}</AlertDescription>
        </Alert>

        <Card title="Invite someone">
            <form
                class="flex flex-col gap-4 px-4 py-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="invite_email">Email</Label>
                        <AppInput
                            id="invite_email"
                            v-model="invite.email"
                            type="email"
                            required
                        />
                        <p
                            v-if="invite.errors.email"
                            class="text-[11px] text-state-danger"
                        >
                            {{ invite.errors.email }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="invite_role">Role</Label>
                        <select
                            id="invite_role"
                            v-model="invite.role_id"
                            required
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option value="" disabled>Choose a role</option>
                            <option
                                v-for="role in assignableRoles"
                                :key="role.id"
                                :value="role.id"
                            >
                                {{ role.name }}
                            </option>
                        </select>
                        <p
                            v-if="invite.errors.role_id"
                            class="text-[11px] text-state-danger"
                        >
                            {{ invite.errors.role_id }}
                        </p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <AppButton type="submit" :disabled="invite.processing">
                        <UserPlus class="size-4" aria-hidden="true" />
                        Send invitation
                    </AppButton>
                </div>
            </form>
        </Card>

        <InvitationLinkPanel v-if="issuedLink" :link="issuedLink" />

        <Card title="Pending invitations">
            <EmptyState
                v-if="invitations.length === 0"
                title="No invitations outstanding"
                description="Anyone you invite shows up here until they accept."
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
                        @click="issueLink(invitation.id, invitation.email)"
                        >Get link</AppButton
                    >
                    <AppButton
                        variant="ghost"
                        @click="revokeInvitation(invitation.id)"
                        >Revoke</AppButton
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
                    <AppButton
                        v-else
                        variant="ghost"
                        @click="revoke(member.id, member.name)"
                        >Revoke access</AppButton
                    >
                </li>
            </ul>
        </Card>
    </div>
</template>
