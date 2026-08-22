<script setup lang="ts">
/**
 * S19 — deal people.
 *
 * PRD §7.2 calls separating this from access roles *"the single biggest
 * simplification available"*: Seller, Buyer and Co-Agent are somebody's part
 * in **one transaction**, not permission to use the software. The same person
 * sells in March and buys in June.
 *
 * ## The state that earns the screen
 *
 * **Missing required role.** A workflow with a gate on the lender, or a
 * message whose recipient rule is "the Seller", needs that participant to
 * exist — and finding out three weeks later, when an advance is refused, is
 * the expensive way. The roles are named rather than counted: "no Seller yet"
 * is actionable, "1 role missing" sends somebody looking for which.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import AddParticipantDialog from '@/components/app/AddParticipantDialog.vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import Heading from '@/components/app/Heading.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';

type Participant = {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    isPrimary: boolean;
    notes: string | null;
    personUrl: string;
};

const props = defineProps<{
    deal: { id: string; name: string; sideLabel: string };
    roles: { role: string; label: string; people: Participant[] }[];
    missingRoles: { value: string; label: string }[];
    participantRoles: Record<string, string>;
}>();

const adding = ref(false);
/** Preselects the role when the prompt to add came from a named gap. */
const suggestedRole = ref<string | null>(null);

const promote = useForm({ participant_role: '', is_primary: true, notes: '' });

/*
 * Nothing on this path errors today — but `replace()` refuses a move into a
 * role somebody already holds, and the first error it ever returns here would
 * otherwise be invisible.
 */
const promoteError = computed(
    () =>
        promote.errors.participant_role ??
        promote.errors.is_primary ??
        promote.errors.notes ??
        null,
);

function openFor(role: string | null): void {
    suggestedRole.value = role;
    adding.value = true;
}

function makePrimary(role: string, participant: Participant): void {
    promote.participant_role = role;
    promote.is_primary = true;
    promote.notes = participant.notes ?? '';

    promote.patch(`/deals/${props.deal.id}/people/${participant.id}`, {
        preserveScroll: true,
    });
}

/*
 * IA §7: **Remove** detaches, **Delete** destroys. The copy says which, because
 * the difference is the whole reassurance — taking the opposing agent off a
 * deal that fell through must not read as deleting them from the address book.
 */
function remove(participant: Participant): void {
    if (
        !window.confirm(
            `Remove ${participant.name} from this deal? They stay in your people and on every other deal they're on.`,
        )
    ) {
        return;
    }

    router.delete(`/deals/${props.deal.id}/people/${participant.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`People — ${deal.name}`" />

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="People"
                :description="`Who is involved in ${deal.name}, and as what.`"
            />
            <AppButton @click="openFor(null)">
                <UserPlus class="size-4" aria-hidden="true" />
                Add participant
            </AppButton>
        </div>

        <!--
            Named, and offering the action that closes the gap. A warning that
            only states a problem makes somebody go and find the button.
        -->
        <Alert v-if="promoteError" variant="destructive">
            <AlertDescription>{{ promoteError }}</AlertDescription>
        </Alert>

        <Alert v-if="missingRoles.length > 0">
            <AlertDescription class="flex flex-wrap items-center gap-2">
                <span
                    >This {{ deal.sideLabel.toLowerCase() }} deal has no
                    <template
                        v-for="(role, index) in missingRoles"
                        :key="role.value"
                        ><strong>{{ role.label }}</strong
                        ><span v-if="index < missingRoles.length - 1">
                            or
                        </span></template
                    >
                    yet. Automations that write to the client need one.</span
                >
                <AppButton
                    v-for="role in missingRoles"
                    :key="role.value"
                    variant="secondary"
                    size="compact"
                    @click="openFor(role.value)"
                >
                    Add {{ role.label }}
                </AppButton>
            </AlertDescription>
        </Alert>

        <EmptyState
            v-if="roles.length === 0"
            title="Nobody on this deal yet"
            description="Add the client first — everything the workflow sends goes to somebody here."
        />

        <Card v-for="group in roles" :key="group.role" :title="group.label">
            <ul class="flex flex-col">
                <li
                    v-for="participant in group.people"
                    :key="participant.id"
                    class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <TextLink
                            :href="participant.personUrl"
                            class="truncate text-13 font-medium"
                            >{{ participant.name }}</TextLink
                        >
                        <span
                            v-if="participant.email || participant.phone"
                            class="truncate text-[11px] text-muted-foreground"
                            >{{
                                [participant.email, participant.phone]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}</span
                        >
                    </span>

                    <StatusBadge
                        v-if="participant.isPrimary"
                        tone="info"
                        label="Main contact"
                        dotless
                    />
                    <AppButton
                        v-else
                        variant="ghost"
                        size="compact"
                        @click="makePrimary(group.role, participant)"
                        >Make main contact</AppButton
                    >

                    <AppButton
                        variant="ghost"
                        size="compact"
                        @click="remove(participant)"
                        >Remove</AppButton
                    >
                </li>
            </ul>
        </Card>

        <AddParticipantDialog
            v-model:open="adding"
            :deal-id="deal.id"
            :participant-roles="participantRoles"
            :suggested-role="suggestedRole"
        />
    </div>
</template>
