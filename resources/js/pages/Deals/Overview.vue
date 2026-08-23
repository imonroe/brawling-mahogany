<script setup lang="ts">
/**
 * S15 — the deal overview (PRD §4.3 F3.7 · Design System §9.2 · issue #75).
 *
 * ## The one thing this screen has to get right
 *
 * Issue #75: *"If a user has to scroll or click to learn what is blocking the
 * deal, the screen has failed."* So the unmet gates sit in the right-hand pane
 * of the current-stage card, at the top of column A, spelled out — each with
 * the sentence its own evaluator wrote and, where the evaluator knows one, a
 * link to the thing that clears it (PRD §5.4). Not a count, and not the first
 * one: `AdvanceResult`'s docblock explains why carrying every unmet gate
 * matters — *"told about one gate, somebody clears it, clicks again, and is
 * told about the next. Three round trips to learn what one screen could have
 * said."* The same argument applies before the click, which is what this
 * screen is.
 *
 * ## §9.2's composition, with one departure
 *
 * ```
 * p-6, gap-5
 * ├─ Progress strip                    one rail per workflow
 * └─ Grid
 *    ├─ Column A (flex-1)   Current stage card (2 panes), Activity card
 *    └─ Column B (w-[340px]) Property, People, Dates, Documents
 * ```
 *
 * The departure is that the strip and the stage card repeat **per workflow**.
 * PRD §7.5 gives a deal concurrent workflows on purpose — pre-listing
 * improvements and the sale run at once — and §9.2's recipe describes one.
 * `App\Support\Deals\DealHeader` carries the same argument for §8.4's single
 * Advance button.
 *
 * ## Sections whose slice has not landed
 *
 * Dates & Deadlines (issue 109, Slice 4) and Documents (issue 104, Slice 3)
 * are laid out
 * as first-class cards that say what will go there, which is #75's explicit
 * instruction: *"not as afterthoughts wedged in when their slice lands."*
 */
import { Head, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    FileText,
    Home,
    TriangleAlert,
    Users,
    Workflow as WorkflowIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AttachWorkflowDialog from '@/components/app/AttachWorkflowDialog.vue';
import Card from '@/components/app/Card.vue';
import DateChip from '@/components/app/DateChip.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { usePermissions } from '@/composables/usePermissions';
import {
    formatAddress,
    formatCount,
    formatDateTime,
    formatLocality,
} from '@/lib/formatters';
import { stateTone } from '@/lib/states';
import type { Tone } from '@/lib/states';
import { cn } from '@/lib/utils';

type GateRow = {
    id: string;
    label: string;
    gateType: string;
    isBlocking: boolean;
    gateState: string;
    met: boolean;
    explanation: string;
    linkTarget: Record<string, string>;
};

type StageDot = {
    id: string;
    name: string;
    state: string;
    isCurrent: boolean;
};

type WorkflowCard = {
    id: string;
    name: string;
    state: string;
    isRunning: boolean;
    stages: StageDot[];
    currentStage: {
        id: string;
        name: string;
        state: string;
        description: string | null;
        plannedEnd: string | null;
        position: number | null;
        total: number;
    } | null;
    gates: GateRow[];
    canAdvance: boolean;
};

const props = defineProps<{
    dealHeader: DealHeaderProps;
    workflows: WorkflowCard[];
    subjectProperty: {
        id: string;
        name: string;
        address: {
            street: string | null;
            unit: string | null;
            city: string | null;
            state: string | null;
            postalCode: string | null;
        };
        status: string;
    } | null;
    candidateCount: number;
    participants: {
        id: string;
        name: string;
        roleLabel: string;
        isPrimary: boolean;
    }[];
    participantCount: number;
    activity: {
        id: string;
        eventType: string;
        summary: string;
        occurredAt: string;
        actorName: string | null;
    }[];
}>();

const { can } = usePermissions();

const attaching = ref(false);

const dealUrl = computed(() => `/deals/${props.dealHeader.id}`);

const subjectAddress = computed(() =>
    props.subjectProperty ? formatAddress(props.subjectProperty.address) : null,
);

/*
 * The rail's tone comes from the stage state table, never from this file
 * (Frontend conventions §3, and `lib/states.ts` throws on a state nobody has
 * written down). Blocked is amber and complete is green because IA §8 says so.
 */
const RAIL_TONE: Record<Tone, string> = {
    neutral: 'bg-state-neutral',
    info: 'bg-state-info',
    success: 'bg-state-success',
    warning: 'bg-state-warning',
    danger: 'bg-state-danger',
};

function railClass(stage: StageDot): string {
    return cn(
        'h-1.5 flex-1 rounded-full',
        RAIL_TONE[stateTone('stage', stage.state)],
        stage.isCurrent ? 'ring-2 ring-primary ring-offset-1' : '',
    );
}

/**
 * Where an unmet gate says to go, or null when it cannot say.
 *
 * The evaluator writes `linkTarget`; PRD §5.4 wants *"each unmet gate links
 * directly to the thing that clears it"*, and only the evaluator knows what
 * that thing is.
 *
 * **Only routes that exist.** `deal_field` resolves to the properties tab,
 * which is built. `tasks` does not resolve to anything: S17 is unbuilt, there
 * is no `deals/{deal}/tasks` route, and `DealHeader` already draws that tab as
 * inert for exactly that reason — so linking to it here rendered "Go and clear
 * it" over a 404, on the one screen whose whole promise is telling somebody
 * what to do next. `gate`, `gate_config` and `awaiting_slice` resolve to
 * nothing for the same reason, their screens being S23 and S43.
 *
 * A dead link is worse than a sentence, and this function said so while
 * emitting one.
 */
function linkFor(gate: GateRow): string | null {
    switch (gate.linkTarget.type) {
        case 'deal_field':
            return `${dealUrl.value}/properties`;
        default:
            return null;
    }
}

function advance(workflow: WorkflowCard): void {
    if (!workflow.currentStage) {
        return;
    }

    router.post(
        `${dealUrl.value}/workflows/${workflow.id}/advance`,
        { expected_stage_id: workflow.currentStage.id },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="dealHeader.name" />

    <div class="flex flex-1 flex-col gap-5 p-6">
        <!--
            #75 names "no workflow attached" as a key state, and it is the one
            a new team meets first. It is also the state in which nothing on
            this deal will ever move, so the way out of it is the action.
        -->
        <EmptyState
            v-if="workflows.length === 0"
            :icon="WorkflowIcon"
            title="No workflow on this deal yet"
            description="A workflow is what moves a deal along — its stages, its gates, and the tasks under each. Nothing here will progress until one is attached."
            class="rounded-lg border bg-card"
        >
            <template #action>
                <AppButton v-if="can('deals.manage')" @click="attaching = true">
                    Attach a workflow
                </AppButton>
            </template>
        </EmptyState>

        <template v-else>
            <!-- §9.2's progress strip: one rail per workflow, full width. -->
            <Card
                v-for="workflow in workflows"
                :key="`rail-${workflow.id}`"
                body-class="gap-2 px-4 py-3"
            >
                <template #header>
                    <h3 class="text-13 font-semibold text-card-foreground">
                        {{ workflow.name }}
                    </h3>
                </template>
                <template #badge>
                    <StatusBadge domain="workflow" :state="workflow.state" />
                </template>

                <div class="flex items-center gap-1" role="presentation">
                    <span
                        v-for="stage in workflow.stages"
                        :key="stage.id"
                        :class="railClass(stage)"
                    />
                </div>
                <p class="text-[11px] text-muted-foreground">
                    <template v-if="workflow.currentStage?.position">
                        Stage {{ workflow.currentStage.position }} of
                        {{ workflow.currentStage.total }} ·
                        {{ workflow.currentStage.name }}
                    </template>
                    <template v-else>
                        {{ formatCount(workflow.stages.length, 'stage') }} · no
                        stage in progress
                    </template>
                </p>
            </Card>
        </template>

        <div class="flex flex-1 flex-col gap-5 lg:flex-row">
            <div class="flex min-w-0 flex-1 flex-col gap-5">
                <!--
                    §9.2's current stage card: header, then a two-pane body.
                    Left is the stage somebody is standing in; right is what is
                    stopping them leaving it. The second pane is the whole
                    point of the screen (#75), so it is never behind a click.
                -->
                <Card
                    v-for="workflow in workflows.filter((w) => w.currentStage)"
                    :key="`stage-${workflow.id}`"
                    :title="workflow.currentStage!.name"
                >
                    <template #badge>
                        <StatusBadge
                            domain="stage"
                            :state="workflow.currentStage!.state"
                        />
                    </template>
                    <template #action>
                        <AppButton
                            v-if="workflow.isRunning && can('workflow.advance')"
                            size="compact"
                            :disabled="!workflow.canAdvance"
                            @click="advance(workflow)"
                            >Advance stage</AppButton
                        >
                    </template>

                    <div class="flex flex-col md:flex-row">
                        <div
                            class="flex min-w-0 flex-1 flex-col gap-2 px-4 py-3"
                        >
                            <p
                                v-if="workflow.currentStage!.description"
                                class="text-13 text-muted-foreground"
                            >
                                {{ workflow.currentStage!.description }}
                            </p>
                            <p v-else class="text-13 text-muted-foreground">
                                Part of {{ workflow.name }}.
                            </p>
                            <DateChip
                                v-if="workflow.currentStage!.plannedEnd"
                                :date="workflow.currentStage!.plannedEnd"
                                relative
                            />
                        </div>

                        <div
                            class="flex min-w-0 flex-1 flex-col gap-2 border-t px-4 py-3 md:border-t-0 md:border-l"
                        >
                            <p
                                v-if="workflow.gates.length === 0"
                                class="text-13 text-muted-foreground"
                            >
                                Nothing is holding this stage up.
                            </p>
                            <template v-else>
                                <p
                                    class="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground uppercase"
                                >
                                    <TriangleAlert
                                        class="size-3.5 text-state-warning"
                                        aria-hidden="true"
                                    />
                                    {{
                                        formatCount(
                                            workflow.gates.length,
                                            'gate',
                                        )
                                    }}
                                    to clear
                                </p>
                                <ul class="flex flex-col gap-2">
                                    <li
                                        v-for="gate in workflow.gates"
                                        :key="gate.id"
                                        class="flex flex-col gap-0.5"
                                    >
                                        <span
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span
                                                class="text-13 font-medium text-foreground"
                                                >{{ gate.label }}</span
                                            >
                                            <!--
                                                Overridden first. IA §8 makes
                                                Overridden a state of its own,
                                                distinct from Met — the gate
                                                "should have been met and was
                                                not, and you proceeded anyway".
                                                Testing `isBlocking` first hid
                                                that entirely: `blocksAdvance()`
                                                is `is_blocking && ! overridden`,
                                                so an overridden gate is not
                                                blocking and took the Advisory
                                                branch, which says the opposite
                                                of what happened.
                                            -->
                                            <StatusBadge
                                                v-if="
                                                    gate.gateState ===
                                                    'overridden'
                                                "
                                                domain="gate"
                                                :state="gate.gateState"
                                            />
                                            <StatusBadge
                                                v-else-if="!gate.isBlocking"
                                                tone="neutral"
                                                label="Advisory"
                                                dotless
                                            />
                                            <StatusBadge
                                                v-else
                                                domain="gate"
                                                :state="gate.gateState"
                                            />
                                        </span>
                                        <!--
                                            The evaluator's own sentence, not a
                                            fragment assembled here: "3 of 5
                                            required tasks are still open" and
                                            "no inspection report is attached"
                                            are not the same sentence with
                                            different nouns.
                                        -->
                                        <span
                                            class="text-13 text-muted-foreground"
                                            >{{ gate.explanation }}</span
                                        >
                                        <TextLink
                                            v-if="linkFor(gate)"
                                            :href="linkFor(gate)!"
                                            class="w-fit text-[11px]"
                                            >Go and clear it</TextLink
                                        >
                                    </li>
                                </ul>
                            </template>
                        </div>
                    </div>
                </Card>

                <Card title="Activity" class="flex-1">
                    <template #action>
                        <TextLink :href="`${dealUrl}/people`"
                            >Everyone on this deal</TextLink
                        >
                    </template>
                    <EmptyState
                        v-if="activity.length === 0"
                        title="Nothing has happened yet"
                        description="Everything anybody does on this deal shows up here, newest first."
                    />
                    <ul v-else class="flex flex-col">
                        <li
                            v-for="entry in activity"
                            :key="entry.id"
                            class="flex flex-col gap-0.5 border-b px-4 py-2.5 last:border-b-0"
                        >
                            <span class="text-13 text-foreground">{{
                                entry.summary
                            }}</span>
                            <span class="text-[11px] text-muted-foreground"
                                >{{ formatDateTime(entry.occurredAt)
                                }}<template v-if="entry.actorName">
                                    · {{ entry.actorName }}</template
                                ></span
                            >
                        </li>
                    </ul>
                </Card>
            </div>

            <div class="flex w-full flex-col gap-5 lg:w-[340px]">
                <Card title="Property">
                    <template #action>
                        <TextLink :href="`${dealUrl}/properties`"
                            >All properties</TextLink
                        >
                    </template>
                    <div
                        v-if="subjectProperty && subjectAddress"
                        class="flex flex-col gap-1 px-4 py-3"
                    >
                        <TextLink
                            :href="`/properties/${subjectProperty.id}`"
                            class="truncate text-13 font-medium"
                            >{{
                                subjectAddress.line1 || subjectProperty.name
                            }}</TextLink
                        >
                        <span class="text-[11px] text-muted-foreground">{{
                            formatLocality(subjectProperty.address)
                        }}</span>
                        <span class="flex items-center gap-2 pt-1">
                            <StatusBadge
                                domain="property"
                                :state="subjectProperty.status"
                            />
                            <span
                                v-if="candidateCount > 0"
                                class="text-[11px] text-muted-foreground"
                                >{{
                                    formatCount(
                                        candidateCount,
                                        'other property',
                                        'other properties',
                                    )
                                }}
                                on this deal</span
                            >
                        </span>
                    </div>
                    <!--
                        #75's fifth key state. A buyer's deal opening before
                        there is a house is normal, not broken (issue #62), so
                        this says which and offers the way forward.
                    -->
                    <EmptyState
                        v-else
                        :icon="Home"
                        title="No subject property yet"
                        :description="
                            candidateCount > 0
                                ? `${formatCount(candidateCount, 'property', 'properties')} linked, none of them the subject yet. Promote one when an offer is accepted.`
                                : 'A buyer’s deal often opens before there is a house. Link one from the Properties tab.'
                        "
                    >
                        <template #action>
                            <AppButton
                                variant="secondary"
                                size="compact"
                                :href="`${dealUrl}/properties`"
                                >Properties</AppButton
                            >
                        </template>
                    </EmptyState>
                </Card>

                <Card title="People">
                    <template #action>
                        <TextLink :href="`${dealUrl}/people`"
                            >All {{ participantCount }}</TextLink
                        >
                    </template>
                    <EmptyState
                        v-if="participants.length === 0"
                        :icon="Users"
                        title="Nobody on this deal yet"
                        description="Add the client first — everything the workflow sends goes to somebody here."
                    >
                        <template #action>
                            <AppButton
                                variant="secondary"
                                size="compact"
                                :href="`${dealUrl}/people`"
                                >Add a participant</AppButton
                            >
                        </template>
                    </EmptyState>
                    <ul v-else class="flex flex-col">
                        <li
                            v-for="participant in participants"
                            :key="participant.id"
                            class="flex h-[42px] items-center gap-2 border-b px-4 last:border-b-0"
                        >
                            <span
                                class="min-w-0 flex-1 truncate text-13 text-foreground"
                                >{{ participant.name }}</span
                            >
                            <StatusBadge
                                v-if="participant.isPrimary"
                                tone="info"
                                label="Main contact"
                                dotless
                            />
                            <span class="text-[11px] text-muted-foreground">{{
                                participant.roleLabel
                            }}</span>
                        </li>
                    </ul>
                </Card>

                <!--
                    Built now, filled in Slice 4 (issue 109). #75: state what will
                    go there rather than wedging it in later.
                -->
                <Card title="Dates &amp; Deadlines">
                    <EmptyState
                        :icon="CalendarClock"
                        title="Dates arrive with slice 4"
                        description="Inspection deadlines, financing contingencies and the closing date will live here, each derived from the date before it — and the date-reached gate will read from them."
                    />
                </Card>

                <!-- Built now, filled in Slice 3 (issue 104). -->
                <Card title="Documents">
                    <EmptyState
                        :icon="FileText"
                        title="Documents arrive with slice 3"
                        description="Disclosures and inspection reports will live here, categorised, and the document-present gate will read from them. Executed contracts stay in your e-signature platform."
                    />
                </Card>
            </div>
        </div>
    </div>

    <AttachWorkflowDialog v-model:open="attaching" :deal-id="dealHeader.id" />
</template>
