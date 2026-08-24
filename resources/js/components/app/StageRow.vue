<script setup lang="ts">
/**
 * One row of Design System §7.4's stage rail (S16 · #76).
 *
 * ```
 * ┌──────┬────────────────────────────────────────┐
 * │ rail │ card                                   │
 * │ w-26 │ flex-1, pb-3.5                         │
 * │  ◉   │ collapsed h-11 · expanded four bands   │
 * │  │   │                                        │
 * └──────┴────────────────────────────────────────┘
 * ```
 *
 * **The connector needs a definite height to stretch into**, which is why §7.4
 * gives explicit row heights rather than letting the card size the row. The
 * rail column is `self-stretch` and the connector is `flex-1`, so the line
 * reaches from this marker to the next one however tall the card grows —
 * including when the card is expanded and the row is 302px rather than 58.
 *
 * The last row has no connector: a line trailing past the final stage draws a
 * step that does not exist.
 */
import { ChevronDown, ChevronUp, Flag, ShieldAlert, Zap } from '@lucide/vue';
import { computed } from 'vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateShort } from '@/lib/formatters';
import type { GateSummary } from '@/lib/gates';
import { cn } from '@/lib/utils';
import AppButton from './AppButton.vue';
import GateRow from './GateRow.vue';
import { MARKER_TONE, stageMarker } from './stageRail';
import StatusBadge from './StatusBadge.vue';
import TaskItem from './TaskItem.vue';

export type StageTask = {
    id: string;
    title: string;
    state: string;
    isRequired: boolean;
    dueDate: string | null;
};

export type TimelineStage = {
    id: string;
    name: string;
    position: number;
    isActive: boolean;
    state: string;
    isMilestone: boolean;
    plannedStart: string | null;
    plannedEnd: string | null;
    actualStart: string | null;
    actualEnd: string | null;
    skippedReason: string | null;
    hasOverride: boolean;
    tasks: { total: number; complete: number; items: StageTask[] };
    gates: GateSummary[];
    gateCounts: Record<string, number>;
};

const props = defineProps<{
    stage: TimelineStage;
    /** How many stages the rail holds, for the row's accessible name. */
    total: number;
    isLast: boolean;
    expanded: boolean;
    canAdvance: boolean;
    /** Null when the workflow is not running — see the rail's refusal line. */
    advanceRefusal: string | null;
}>();

const emit = defineEmits<{
    toggle: [];
    advance: [];
}>();

const { can } = usePermissions();

/**
 * What the toggle is called when you cannot see the rail.
 *
 * The marker, the connector and the row's position down the page are the whole
 * argument for a rail over a list — a stage is legible **in its sequence** —
 * and every one of them is visual. A screen reader otherwise hears twenty
 * buttons named after twenty stages, in no stated order, with nothing saying
 * which one is current.
 */
const accessibleName = computed(
    () =>
        `Stage ${props.stage.position} of ${props.total}: ${props.stage.name}` +
        (props.stage.isActive ? ', current stage' : ''),
);

const marker = computed(() => stageMarker(props.stage));

/*
 * What the marker is *saying*, for the tests and for anybody inspecting the
 * DOM — derived from `stageMarker` rather than re-deciding beside it.
 *
 * It re-decided for one round, and drifted the moment the rule changed: after
 * the override marker was narrowed to finished stages, an active overridden
 * stage drew a `Loader` under an attribute still reading `overridden`. A hook
 * that describes something other than what rendered is worse than no hook,
 * because it is what a test believes.
 */
const markerState = computed(() =>
    marker.value.icon === ShieldAlert ? 'overridden' : props.stage.state,
);

/*
 * §7.4's meta string: `15 Jul–2 Aug · 18 days · 8 of 8 tasks`.
 *
 * Actual dates win over planned ones, because once a stage has really started
 * the plan is no longer what happened — and a stage showing its plan after the
 * fact is the screen quietly disagreeing with the record. Nothing here spells a
 * date itself: IA §10 fixes every rule and `lib/formatters.ts` owns them.
 *
 * `formatDateShort`, not `formatDate`, and that is a choice rather than a
 * shortcut: `formatDate` carries the weekday, so a range reads *"Thu, Aug 20–
 * Sat, Aug 22"* — twice the width for a fact nobody needs, in a 44px row that
 * also holds a name, a badge and a task count. §7.4's own example is
 * `15 Jul–2 Aug`.
 */
const dates = computed<{ start: string | null; end: string | null }>(() => ({
    start: props.stage.actualStart ?? props.stage.plannedStart,
    end: props.stage.actualEnd ?? props.stage.plannedEnd,
}));

const dateRange = computed<string | null>(() => {
    const { start, end } = dates.value;

    if (start === null && end === null) {
        return null;
    }

    if (start === null || end === null) {
        return formatDateShort((start ?? end) as string);
    }

    return `${formatDateShort(start)}–${formatDateShort(end)}`;
});

/**
 * How long the stage ran, in whole days, and only once both ends are real.
 *
 * A duration over a *planned* range is a forecast, not a fact, and reads as one
 * in a row that otherwise reports what happened. So this is null until the
 * stage has both an actual start and an actual end — which is to say, until it
 * is finished.
 */
const duration = computed<string | null>(() => {
    const { actualStart, actualEnd } = props.stage;

    if (actualStart === null || actualEnd === null) {
        return null;
    }

    const days = Math.max(
        1,
        Math.round(
            (Date.parse(actualEnd) - Date.parse(actualStart)) / 86_400_000,
        ),
    );

    return `${days} ${days === 1 ? 'day' : 'days'}`;
});

const taskSummary = computed<string | null>(() =>
    props.stage.tasks.total === 0
        ? null
        : `${props.stage.tasks.complete} of ${props.stage.tasks.total} tasks`,
);

const meta = computed(() =>
    [dateRange.value, duration.value, taskSummary.value]
        .filter((part): part is string => part !== null)
        .join(' · '),
);

/*
 * §7.4: *"Requirements to advance · 2 of 3 cleared"*, the count in
 * `state-warning` when something is still blocking.
 *
 * **"Cleared", not "met"** — the count is met *plus* overridden, which
 * `StageReadiness::counts()` already computes, because "1 of 1 met" over a row
 * badged Overridden says the opposite of what happened.
 */
const blocking = computed(() => props.stage.gateCounts.blocking ?? 0);

const gateHeading = computed(
    () =>
        `Requirements to advance · ${props.stage.gateCounts.cleared ?? 0} of ${
            props.stage.gateCounts.total ?? 0
        } cleared`,
);

const taskHeading = computed(
    () =>
        `Tasks · ${props.stage.tasks.complete} of ${props.stage.tasks.total} complete`,
);

/** The one-line condensation of §7.4's "what happens when you advance". */
const footerLine = computed(() => {
    if (props.advanceRefusal !== null) {
        return props.advanceRefusal;
    }

    return blocking.value > 0
        ? `${blocking.value} requirement${blocking.value === 1 ? '' : 's'} still to clear`
        : 'Completes this stage and starts the next one';
});
</script>

<template>
    <div class="flex gap-3" data-slot="stage-row">
        <!--
            The rail column. `self-stretch` so the connector can reach the next
            marker: without it the column is only as tall as the marker and the
            line between stages disappears.
        -->
        <div class="flex w-[26px] shrink-0 flex-col items-center self-stretch">
            <div
                :class="
                    cn(
                        'flex shrink-0 items-center justify-center rounded-full border-[1.5px]',
                        MARKER_TONE[marker.tone],
                        stage.isActive ? 'size-[26px] border-2' : 'size-[22px]',
                    )
                "
                data-slot="stage-marker"
                :data-marker-state="markerState"
            >
                <component
                    :is="marker.icon"
                    class="size-3"
                    aria-hidden="true"
                />
            </div>

            <div
                v-if="!isLast"
                class="w-0.5 flex-1 bg-border"
                aria-hidden="true"
            />
        </div>

        <div class="flex-1 pb-3.5">
            <!--
                **One button, whichever way the row is showing.**

                The collapsed card and the expanded card's header band are the
                same control saying the same thing, so they are the same
                element. Two of them — a `v-if` pair in different parents —
                meant every toggle destroyed the focused node and dropped
                keyboard focus to `<body>`, which turns "open a stage" into
                "start again from the top of the page".
            -->
            <div
                :class="
                    cn(
                        'overflow-hidden rounded-lg border bg-card',
                        expanded &&
                            stage.isActive &&
                            'border-[1.5px] border-primary',
                    )
                "
                data-slot="stage-card"
            >
                <button
                    type="button"
                    :class="
                        cn(
                            'flex w-full items-center gap-2.5 px-3.5 text-left transition-colors',
                            expanded
                                ? 'h-12 border-b bg-accent'
                                : 'h-11 hover:bg-accent',
                        )
                    "
                    :aria-expanded="expanded"
                    :aria-label="accessibleName"
                    @click="emit('toggle')"
                >
                    <span
                        :class="
                            cn(
                                'truncate font-semibold',
                                expanded ? 'text-[15px]' : 'text-sm',
                                // §7.4's Skipped row: `minus`, **card text
                                // muted**. A stage nobody worked should not
                                // read at the same weight as the twelve that
                                // were.
                                stage.state === 'skipped' &&
                                    'text-muted-foreground',
                            )
                        "
                        data-slot="stage-name"
                        >{{ stage.name }}</span
                    >

                    <!--
                        §7.4's milestone pill, on the collapsed row only — the
                        expanded header band is `[name] [badge] [meta]
                        [chevron]` and has no pill in it either.

                        It reads **Milestone**, not `milestoneLabel`. The pill
                        marks *that* this stage is a moment; the label is the
                        sentence a **client** is told about it (IA §3), and its
                        home is the status page. Rendering it here put "Under
                        contract" in a slot §7.4 specifies as the word
                        `Milestone`, and beside a stage usually named the same
                        thing.

                        Tinted `state-success` once the moment has happened and
                        `state-neutral` while it is still ahead: a moment that
                        has not arrived is not an achievement yet.
                    -->
                    <span
                        v-if="stage.isMilestone && !expanded"
                        :class="
                            cn(
                                'flex shrink-0 items-center gap-1 rounded-full px-[7px] py-0.5 text-[11px] font-semibold',
                                stage.state === 'complete'
                                    ? 'bg-state-success-bg text-state-success'
                                    : 'bg-state-neutral-bg text-state-neutral',
                            )
                        "
                        data-slot="milestone-pill"
                    >
                        <Flag class="size-[11px]" aria-hidden="true" />
                        Milestone
                    </span>

                    <StatusBadge
                        v-if="expanded"
                        domain="stage"
                        :state="stage.state"
                    />

                    <span class="flex-1" />

                    <span
                        v-if="meta"
                        class="hidden truncate text-xs text-muted-foreground sm:inline"
                        data-slot="stage-meta"
                        >{{ meta }}</span
                    >

                    <StatusBadge
                        v-if="!expanded"
                        domain="stage"
                        :state="stage.state"
                    />

                    <component
                        :is="expanded ? ChevronUp : ChevronDown"
                        class="size-[15px] shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </button>

                <template v-if="expanded">
                    <!--
                        The two-pane body. Stacked below `lg` rather than
                        squeezed: §7.4 fixes the requirements pane at 340px, and
                        two panes at that width on a phone is two columns of two
                        words.
                    -->
                    <div class="flex flex-col lg:flex-row">
                        <div class="flex flex-1 flex-col gap-[9px] p-3.5">
                            <p
                                class="text-xs font-semibold text-muted-foreground"
                            >
                                {{ taskHeading }}
                            </p>

                            <p
                                v-if="stage.tasks.items.length === 0"
                                class="text-xs text-muted-foreground"
                            >
                                No tasks on this stage.
                            </p>

                            <TaskItem
                                v-for="task in stage.tasks.items"
                                :key="task.id"
                                :title="task.title"
                                :completed="task.state === 'completed'"
                                :due-date="task.dueDate"
                                :meta="task.isRequired ? 'Required' : null"
                                readonly
                            />
                        </div>

                        <div
                            class="flex flex-col gap-[9px] border-t p-3.5 lg:w-[340px] lg:border-t-0 lg:border-l"
                        >
                            <p
                                :class="
                                    cn(
                                        'text-xs font-semibold',
                                        blocking > 0
                                            ? 'text-state-warning'
                                            : 'text-muted-foreground',
                                    )
                                "
                                data-slot="gate-heading"
                            >
                                {{ gateHeading }}
                            </p>

                            <p
                                v-if="stage.gates.length === 0"
                                class="text-xs text-muted-foreground"
                            >
                                Nothing has to clear before this stage advances.
                            </p>

                            <!--
                                Plain density, not `boxed`. §7.4 gives the amber
                                box to the advance dialog, where the reader has
                                come specifically to clear something; here the
                                card is one row of a list of twenty and a wall of
                                amber boxes would flatten the difference between
                                the stage in the way and the nineteen that are
                                not.

                                Override is not offered from this row either:
                                §7.4 puts it on the *dialog's* row, and S23 is
                                what the Advance button below opens.
                            -->
                            <GateRow
                                v-for="gate in stage.gates"
                                :key="gate.id"
                                :gate="gate"
                            />
                        </div>
                    </div>

                    <!--
                        §7.4's footer. Advance only on the active stage:
                        advancing is a workflow-level act on whatever stage is
                        current, so a button on a completed row would offer to
                        advance something else.
                    -->
                    <div
                        v-if="stage.isActive"
                        class="flex h-13 items-center gap-2.5 border-t bg-muted px-3.5"
                        data-slot="stage-footer"
                    >
                        <Zap
                            class="size-[15px] shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="truncate text-xs text-muted-foreground">{{
                            footerLine
                        }}</span>
                        <span class="flex-1" />
                        <!--
                            **Hidden, not disabled** — Design System §7.3, and
                            the same guard `DealHeader` and the overview's
                            workflow cards already carry. A third caller written
                            without it is the defect this codebase keeps
                            producing: the server answers 403, so the button was
                            offering an act it could not perform.
                        -->
                        <AppButton
                            v-if="
                                advanceRefusal === null &&
                                can('workflow.advance')
                            "
                            size="compact"
                            :disabled="!canAdvance || undefined"
                            @click="emit('advance')"
                            >Advance stage</AppButton
                        >
                    </div>

                    <!--
                        A skipped stage says why, or says that it does not. IA §7
                        calls conflating Skip with Override legally material, and
                        the difference a reader can see is that one of them
                        always carries a reason.
                    -->
                    <div
                        v-else-if="stage.state === 'skipped'"
                        class="border-t bg-muted px-3.5 py-2.5 text-xs text-muted-foreground"
                        data-slot="skip-reason"
                    >
                        {{
                            stage.skippedReason ??
                            'Skipped. No reason was recorded.'
                        }}
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
