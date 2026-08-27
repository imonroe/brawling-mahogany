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
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { MessageSquarePlus, UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import AddParticipantDialog from '@/components/app/AddParticipantDialog.vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import HandedLinkPanel from '@/components/app/HandedLinkPanel.vue';
import Heading from '@/components/app/Heading.vue';
import LogContactDialog from '@/components/app/LogContactDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { usePermissions } from '@/composables/usePermissions';
import { formatRelativeDate } from '@/lib/formatters';

type Participant = {
    id: string;
    /** The person behind the participation — what S26 logs against. */
    membershipId: string;
    name: string;
    email: string | null;
    phone: string | null;
    isPrimary: boolean;
    notes: string | null;
    personUrl: string;
    /**
     * Their status page access, or null when they have never been given any
     * (#110). The two are different questions and one control cannot answer
     * both.
     */
    statusPage: {
        hasSession: boolean;
        linkIsLive: boolean;
        lastSeenAt: string | null;
        viewCount: number;
    } | null;
};

/**
 * The status page link, when the agent has just asked for one (#110).
 *
 * Flashed by the server rather than sent as a prop, for the reason S74
 * flashes an invitation link: a credential that lives in a prop is a
 * credential in every subsequent partial reload of the screen.
 */
type HandedLink = {
    membershipId: string;
    name: string;
    url: string;
    minutes: number;
};

const props = defineProps<{
    /*
     * The deal identity comes from the header payload every deal tab shares
     * (`App\Support\Deals\DealHeader`), rather than from a second `deal`
     * prop shaped slightly differently on each tab. `DealLayout` renders the
     * header from the same object.
     */
    dealHeader: DealHeaderProps;
    roles: { role: string; label: string; people: Participant[] }[];
    missingRoles: { value: string; label: string }[];
    participantRoles: Record<string, string>;
}>();

const { can } = usePermissions();

const page = usePage();

const handedLink = computed<HandedLink | null>(() => {
    const flashed = page.props.statusPageLink;

    return (flashed ?? null) as HandedLink | null;
});

const adding = ref(false);

/*
 * S26's third entry point (issue #81), and the one where the deal is already
 * known — so the modal opens with both the person and the deal filled in, and
 * the two clicks that remain are the type and the save.
 */
const logging = ref(false);
const loggingAgainst = ref<{ id: string; name: string } | null>(null);

/**
 * The one deal this screen is about, offered to the modal as the only
 * attachment. `PersonDeals` would return every deal the participant is on,
 * which on this screen is a list somebody has to read to find the deal they
 * are already looking at.
 */
const dealsForModal = computed(() => [
    // From the shared header payload, not a per-tab `deal` prop — S15 removed
    // that one so the tabs cannot disagree about what the deal is called.
    { id: props.dealHeader.id, name: props.dealHeader.name },
]);

function logContact(participant: Participant): void {
    loggingAgainst.value = {
        id: participant.membershipId,
        name: participant.name,
    };
    logging.value = true;
}
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

    promote.patch(`/deals/${props.dealHeader.id}/people/${participant.id}`, {
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

    router.delete(`/deals/${props.dealHeader.id}/people/${participant.id}`, {
        preserveScroll: true,
    });
}

/*
 * ---------------------------------------------------------------------------
 * The client's own status page (#110)
 * ---------------------------------------------------------------------------
 */

/**
 * The roles a status page is *about*.
 *
 * The page describes one client's transaction, so only the client sees it. A
 * lender or a title company is a participant and is not the person the page is
 * for — and the one this list most exists to exclude is `opposing_agent`,
 * where the control is one click from handing the other side a live view of
 * the deal.
 *
 * Kept here rather than derived from the role's own enum: `ParticipantRole::
 * isTeamSide()` answers a different question (who to ring), and a second
 * meaning on one method is how the two start disagreeing.
 */
const CLIENT_ROLES = ['seller', 'buyer'];

/** PRD §4.2 F2.2's Read Only role reads this screen and grants nothing. */
const canGrant = computed(() => can('deals.manage'));

/**
 * *"Opened 4 times · last on Tuesday"*, or that they have not yet.
 *
 * The question an agent asks is *"has the client looked?"* — and *"more than
 * once?"* straight after it, because a client who has opened the page six
 * times is a client with a question they have not asked.
 */
function seenLine(participant: Participant): string {
    const access = participant.statusPage;

    if (!access?.lastSeenAt) {
        return 'Has access · not opened yet';
    }

    const times =
        access.viewCount === 1
            ? 'Opened once'
            : `Opened ${access.viewCount} times`;

    return `${times} · last ${formatRelativeDate(access.lastSeenAt)}`;
}

const VISIT = { preserveScroll: true } as const;

function sendStatusPage(participant: Participant): void {
    router.post(
        `/deals/${props.dealHeader.id}/people/${participant.membershipId}/status-page`,
        {},
        VISIT,
    );
}

/**
 * The link, on screen, for the agent to hand over (ADR 0003).
 *
 * A fresh one every time, because issuing revokes what came before — which
 * means a copied link is always the live one and there is never a second
 * working URL somebody forgot about.
 */
function copyStatusPageLink(participant: Participant): void {
    router.post(
        `/deals/${props.dealHeader.id}/people/${participant.membershipId}/status-page/link`,
        {},
        VISIT,
    );
}

function revokeStatusPage(participant: Participant): void {
    /*
     * IA §10: name the object and the consequence. A revoke is not reversible
     * by undo — it is reversible by sending a new link, and saying so is what
     * stops somebody hesitating over a control they should use.
     */
    if (
        !window.confirm(
            `Revoke ${participant.name}’s access to the status page? They will not be able to open it until you send them a new link.`,
        )
    ) {
        return;
    }

    router.delete(
        `/deals/${props.dealHeader.id}/people/${participant.membershipId}/status-page`,
        VISIT,
    );
}
</script>

<template>
    <Head :title="`People — ${dealHeader.name}`" />

    <!--
        §9.2: the DealHeader above is full-bleed and the tab body owns its
        `p-6`. This screen used to render its own `Heading` and no padding at
        all, because there was no deal chrome for it to sit under.
    -->
    <div class="flex flex-col gap-4 p-6">
        <!--
            An `h2` under the DealHeader's `h1`. The deal is what the page is
            about; People is the section of it somebody is reading.
        -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                variant="small"
                title="People"
                :description="`Who is involved in this ${dealHeader.sideLabel.toLowerCase()} deal, and as what.`"
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
                    >This {{ dealHeader.sideLabel.toLowerCase() }} deal has no
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

        <HandedLinkPanel
            v-if="handedLink"
            :link="{
                id: handedLink.membershipId,
                email: handedLink.name,
                url: handedLink.url,
            }"
            label="Status page link for"
            :note="`Read it out, text it, or paste it anywhere. It works once, for ${handedLink.minutes} minutes, and it replaced any link this person already had for this deal.`"
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
                        v-if="can('people.manage')"
                        variant="ghost"
                        size="compact"
                        @click="logContact(participant)"
                    >
                        <MessageSquarePlus class="size-4" aria-hidden="true" />
                        Log contact
                    </AppButton>

                    <!--
                        The client's own status page (#110). Only for the
                        roles a client actually holds — a lender does not get
                        a page about somebody else's sale, and offering the
                        control for every participant is how one gets sent to
                        the opposing agent.
                    -->
                    <template
                        v-if="canGrant && CLIENT_ROLES.includes(group.role)"
                    >
                        <span
                            v-if="participant.statusPage?.hasSession"
                            class="text-[11px] text-muted-foreground"
                            >{{ seenLine(participant) }}</span
                        >

                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="sendStatusPage(participant)"
                            >{{
                                participant.statusPage
                                    ? 'Send a new link'
                                    : 'Send status page'
                            }}</AppButton
                        >

                        <!--
                            ADR 0003's second door, on the screen: the URL, for
                            the phone call. A client who never receives the
                            email is not a client who cannot see the page.
                        -->
                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="copyStatusPageLink(participant)"
                            >Copy link</AppButton
                        >

                        <AppButton
                            v-if="participant.statusPage"
                            variant="ghost"
                            size="compact"
                            @click="revokeStatusPage(participant)"
                            >Revoke access</AppButton
                        >
                    </template>

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
            :deal-id="dealHeader.id"
            :participant-roles="participantRoles"
            :suggested-role="suggestedRole"
        />

        <LogContactDialog
            v-model:open="logging"
            :membership="loggingAgainst"
            :deals="dealsForModal"
            :deal-id="dealHeader.id"
        />
    </div>
</template>
