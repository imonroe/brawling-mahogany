<script setup lang="ts">
/**
 * S68 — extraction history (PRD §4.10 F10.4, §12.3 · issue #118).
 *
 * #118 is the design brief and it is a brief about **readers**, not about
 * data. Three people ask three different things of the same rows:
 *
 * 1. **Audit** — *who confirmed this date, from which page, on what date?*
 *    Asked when something has gone wrong.
 * 2. **Cost** — *what is this team costing, and are we under $2 per deal?*
 *    Asked monthly.
 * 3. **Quality** — *what is the model getting wrong, and has a version change
 *    made it worse?* Asked continuously.
 *
 * So the screen is five regions in that order rather than one sortable table.
 * A single undifferentiated list would answer the first question badly (it has
 * no per-deal shape) and the other two not at all — a rate and a version
 * comparison are aggregates, and an aggregate is not a row you can scroll to.
 *
 * ## What the human changed sits second, above the cost
 *
 * F10.4 asks for the document, the model and version, the prompt version, the
 * raw output, the confidence, and **what the human changed**. #118 calls the
 * last one *"the valuable one — simultaneously the audit trail, the quality
 * metric, and the input to improving the prompt"*, so it is a region with the
 * proposal and the final value **side by side**, near the top. Buried as a
 * column it would be the thing nobody reads; it is the reason the screen
 * exists.
 *
 * ## Critical dates missed shows its target and refuses to show a number
 *
 * PRD §12.3 gives it zero tolerance, and this application cannot measure it.
 * A missed date is one the contract contained and the model never reported —
 * it produces no row anywhere, so every count the live data could compute
 * would be `0`, whether the model is perfect or read one page of twelve.
 *
 * A tile reading **0 missed** would be believed, and CLAUDE.md's rule from S50
 * is the same rule: *report a number, do not imply a limit*. So the row carries
 * the target and names `php artisan extraction:score`, the regression harness
 * that scores against #14's hand-checked corpus, and no count at all. It is
 * shown rather than omitted because dropping it would leave two targets on the
 * screen where PRD §12.3 has three, and a reader would fairly conclude the
 * third does not exist.
 *
 * ## The reset instant is local; the month it ends is not
 *
 * Every other date in this product is asked in the team's timezone.
 * `SpendLedger` argues why the extraction month cannot be: a platform-wide
 * ceiling cannot roll over at thirty different instants. The screen renders the
 * instant in the reader's own zone — that part *is* local, and a UTC timestamp
 * shown raw would be the misleading half — and says in words that the month is
 * counted in UTC, so a team in UTC-7 is not surprised by a reset at 5pm on the
 * 31st.
 */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import Heading from '@/components/app/Heading.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatCount, formatDateTime, formatNumber } from '@/lib/formatters';
import type { Tone } from '@/lib/states';

type Verdict = boolean | null;

type TargetRow = {
    label: string;
    /** The measured figure, already in words. Null when nothing is measurable. */
    value: string | null;
    target: string;
    /** What the figure is computed over — a rate with no denominator is a rumour. */
    basis: string;
    meets: Verdict;
};

const props = defineProps<{
    scorecard: {
        confirmedWithoutEdit: {
            percent: number | null;
            confirmed: number;
            edited: number;
            rejected: number;
            reviewed: number;
            pending: number;
            targetPercent: number;
            meetsTarget: Verdict;
        };
        costPerDeal: {
            deals: number;
            average: string | null;
            total: string;
            overTarget: number;
            target: string;
            meetsTarget: Verdict;
        };
        criticalDates: {
            target: string;
            measuredHere: boolean;
            command: string;
        };
    };
    spend: {
        monthToDate: string;
        cap: string | null;
        percent: number | null;
        warnAtPercent: number;
        resetsAt: string;
    };
    versions: {
        key: string;
        model: string | null;
        modelVersion: string | null;
        promptVersion: string | null;
        attempts: number;
        cost: string | null;
        reviewed: number;
        confirmedWithoutEdit: number | null;
        lastUsedAt: string | null;
    }[];
    edits: {
        id: string;
        label: string;
        fieldTypeLabel: string;
        reviewState: string;
        proposedValue: string;
        finalValue: string | null;
        confidence: number | null;
        sourcePage: number | null;
        reviewedByName: string | null;
        reviewedAt: string | null;
        dealName: string | null;
        documentName: string | null;
        promptVersion: string | null;
        model: string | null;
        url: string | null;
    }[];
    editsTotal: number;
    attempts: {
        id: string;
        state: string;
        kindLabel: string;
        dealName: string | null;
        documentName: string | null;
        model: string | null;
        modelVersion: string | null;
        promptVersion: string | null;
        cost: string | null;
        requestedByName: string | null;
        createdAt: string | null;
        proposals: number;
        pending: number;
        edited: number;
        error: string | null;
        url: string | null;
    }[];
    attemptsTotal: number;
}>();

/**
 * A percentage as words, or null.
 *
 * Null all the way through rather than `0%`. Nothing reviewed yet is not a
 * measurement, and a screen that drew it as zero would raise an alarm about an
 * empty table — which is how somebody learns to stop reading the alarm.
 */
function percentWords(value: number | null): string | null {
    return value === null ? null : `${formatNumber(value)}%`;
}

/**
 * The two rows the live data can answer, and the one it cannot.
 *
 * Assembled here rather than in the template so the third row is written the
 * same way as the other two — a metric with a target and no value, rather than
 * a paragraph bolted underneath a table of real numbers.
 */
const targets = computed<TargetRow[]>(() => {
    const quality = props.scorecard.confirmedWithoutEdit;
    const cost = props.scorecard.costPerDeal;

    return [
        {
            label: 'Dates confirmed without edit',
            value: percentWords(quality.percent),
            target: `Above ${formatNumber(quality.targetPercent)}%`,
            /*
             * PRD §12.3 measures this over extracted **dates**, so the basis
             * says so — and it carries the denominator, because 100% of three
             * proposals is not a quality measurement and reads exactly like
             * 100% of three hundred.
             */
            basis:
                quality.reviewed === 0
                    ? 'No date proposals have been reviewed yet'
                    : `${formatCount(quality.reviewed, 'reviewed date proposal')} · ${formatNumber(quality.edited)} edited, ${formatNumber(quality.rejected)} rejected`,
            meets: quality.meetsTarget,
        },
        {
            label: 'Critical dates missed',
            /*
             * Deliberately null. See this component's docblock: a date the
             * model never reported leaves no row, so any count here would be
             * zero for a perfect model and zero for a useless one.
             */
            value: null,
            target: props.scorecard.criticalDates.target,
            basis: `Not measurable from these rows — a missed date leaves no record. Scored against the hand-checked corpus by ${props.scorecard.criticalDates.command}`,
            meets: null,
        },
        {
            label: 'Cost per deal',
            value: cost.average,
            target: `Under ${cost.target}`,
            basis:
                cost.deals === 0
                    ? 'No deal has used extraction yet'
                    : `Average across ${formatCount(cost.deals, 'deal')} · ${cost.overTarget === 0 ? 'none over target' : `${formatCount(cost.overTarget, 'deal')} over target`}`,
            meets: cost.meetsTarget,
        },
    ];
});

/** Success, danger, or a neutral "not measured here". */
function verdictTone(meets: Verdict): Tone {
    if (meets === null) {
        return 'neutral';
    }

    return meets ? 'success' : 'danger';
}

function verdictLabel(meets: Verdict): string {
    if (meets === null) {
        return 'Not measured here';
    }

    return meets ? 'On target' : 'Off target';
}

/*
 * The bar is drawn only when there is a real ceiling to draw it against.
 *
 * `SpendLedger` treats a **negative** cap as the absence of one; zero is a
 * ceiling of zero and reads as fully spent, which is what `spend.percent`
 * already carries. So the only case with no bar is the one where the server
 * sent no percentage at all — a bar with an invented maximum is the lie S50
 * refuses to tell about storage, and a missing bar over a stopped team is the
 * same lie running the other way.
 */
const capTone = computed<Tone>(() => {
    if (props.spend.percent === null) {
        return 'neutral';
    }

    if (props.spend.percent >= 100) {
        return 'danger';
    }

    return props.spend.percent >= props.spend.warnAtPercent
        ? 'warning'
        : 'success';
});

const capFillClass = computed(() => {
    const tone = capTone.value;

    if (tone === 'danger') {
        return 'bg-state-danger';
    }

    return tone === 'warning' ? 'bg-state-warning' : 'bg-state-success';
});

/** Clamped, because a spend past the ceiling must not overflow its own track. */
const capWidth = computed(() =>
    props.spend.percent === null
        ? '0%'
        : `${Math.min(100, Math.max(0, props.spend.percent))}%`,
);

function versionName(version: (typeof props.versions)[number]): string {
    return [version.model ?? 'Unknown model', version.modelVersion]
        .filter((part): part is string => Boolean(part))
        .join(' · ');
}
</script>

<template>
    <Head title="Extraction history" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Extraction history"
            description="What the model read, what it cost, and what a person changed."
        />

        <!--
            #118's three targets, together, with the one this application
            cannot measure saying so rather than reporting a zero.
        -->
        <Card title="Targets">
            <div
                v-for="row in targets"
                :key="row.label"
                class="flex flex-col gap-1.5 border-b px-4 py-3 last:border-b-0"
                data-slot="target-row"
            >
                <div class="flex items-center gap-2">
                    <span class="text-13 font-medium text-foreground">{{
                        row.label
                    }}</span>
                    <span class="flex-1"></span>
                    <StatusBadge
                        :tone="verdictTone(row.meets)"
                        :label="verdictLabel(row.meets)"
                        dotless
                    />
                </div>

                <div class="flex items-baseline gap-2">
                    <span
                        v-if="row.value"
                        class="text-[26px] leading-none font-semibold text-foreground"
                        >{{ row.value }}</span
                    >
                    <span
                        v-else
                        :class="['text-sm', 'text-muted-foreground']"
                        data-slot="no-figure"
                        >No figure</span
                    >
                    <span :class="['text-xs', 'text-muted-foreground']"
                        >Target: {{ row.target }}</span
                    >
                </div>

                <p :class="['text-xs', 'text-muted-foreground']">
                    {{ row.basis }}
                </p>
            </div>
        </Card>

        <!--
            F10.4's "what the human changed", where #118 puts it: near the top,
            with both values visible. A diff or an "Edited" flag would be the
            one shape useless to all three readers.
        -->
        <Card title="What people changed">
            <template #badge>
                <StatusBadge
                    tone="neutral"
                    :label="String(editsTotal)"
                    dotless
                />
            </template>

            <EmptyState
                v-if="edits.length === 0"
                title="Nobody has edited a proposal yet"
                description="When somebody changes a date or a task before confirming it, the model’s version and theirs both show up here."
            />

            <div
                v-for="edit in edits"
                :key="edit.id"
                class="flex flex-col gap-2 border-b px-4 py-3 last:border-b-0"
                data-slot="edit-row"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-foreground">{{
                        edit.label
                    }}</span>
                    <StatusBadge
                        domain="extractedField"
                        :state="edit.reviewState"
                    />
                    <span :class="['text-xs', 'text-muted-foreground']">{{
                        edit.fieldTypeLabel
                    }}</span>
                </div>

                <!--
                    Side by side, both labelled. The proposal is what the prompt
                    produced and the final value is what the record now says;
                    showing only the second turns the quality metric back into a
                    flag.
                -->
                <dl class="grid grid-cols-2 gap-2" data-slot="change">
                    <div class="flex flex-col gap-0.5">
                        <dt :class="['text-xs', 'text-muted-foreground']">
                            Model proposed
                        </dt>
                        <dd
                            class="text-13 break-words text-foreground"
                            data-slot="proposed-value"
                        >
                            {{ edit.proposedValue }}
                        </dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt :class="['text-xs', 'text-muted-foreground']">
                            Confirmed as
                        </dt>
                        <dd
                            class="text-13 break-words text-foreground"
                            data-slot="final-value"
                        >
                            {{ edit.finalValue }}
                        </dd>
                    </div>
                </dl>

                <p :class="['text-xs', 'text-muted-foreground']">
                    <template v-if="edit.dealName"
                        >{{ edit.dealName }} ·
                    </template>
                    <template v-if="edit.reviewedByName"
                        >{{ edit.reviewedByName }} ·
                    </template>
                    <template v-if="edit.reviewedAt"
                        >{{ formatDateTime(edit.reviewedAt) }} ·
                    </template>
                    <template v-if="edit.sourcePage"
                        >page {{ edit.sourcePage }} ·
                    </template>
                    {{ edit.promptVersion ?? 'no prompt version recorded' }}
                </p>

                <Link
                    v-if="edit.url"
                    :href="edit.url"
                    class="text-13 font-medium underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Open the review
                </Link>
            </div>

            <!--
                Capped rather than paginated, and the cap says what it hides.
                The full per-deal trail lives on S66, which every row links to.
            -->
            <p
                v-if="edits.length < editsTotal"
                :class="['px-4', 'py-2.5', 'text-xs', 'text-muted-foreground']"
                data-slot="edits-cap"
            >
                Showing the {{ formatNumber(edits.length) }} most recent of
                {{ formatCount(editsTotal, 'edit') }}.
            </p>
        </Card>

        <!-- The cost question, against the ceiling that actually stops work. -->
        <Card title="This month">
            <div class="flex flex-col gap-2 px-4 py-3">
                <div class="flex items-baseline gap-2">
                    <span
                        class="text-[26px] leading-none font-semibold text-foreground"
                        data-slot="month-spend"
                        >{{ spend.monthToDate }}</span
                    >
                    <span
                        v-if="spend.cap"
                        :class="['text-xs', 'text-muted-foreground']"
                        >of {{ spend.cap }}
                        <template v-if="spend.percent !== null"
                            >· {{ formatNumber(spend.percent) }}%</template
                        ></span
                    >
                    <span v-else :class="['text-xs', 'text-muted-foreground']"
                        >No monthly ceiling is set for this team.</span
                    >
                </div>

                <div
                    v-if="spend.percent !== null"
                    class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                    role="img"
                    :aria-label="`${formatNumber(spend.percent)}% of this month’s extraction budget used`"
                >
                    <div
                        class="h-full rounded-full"
                        :class="capFillClass"
                        :style="{ width: capWidth }"
                    ></div>
                </div>

                <!--
                    The instant is rendered in the reader's zone, which is
                    correct; the sentence beside it says the month is counted
                    in UTC, which is the part nothing else on the screen could
                    tell them.
                -->
                <p :class="['text-xs', 'text-muted-foreground']">
                    Resets {{ formatDateTime(spend.resetsAt) }}. The extraction
                    month is counted in UTC rather than your timezone, so it can
                    roll over part-way through your last day.
                </p>

                <!--
                    All time, and it says so. The card is titled "This month"
                    and this is the one line in it that is not — a total
                    sitting unlabelled under a monthly figure would be read as
                    the month's.
                -->
                <p :class="['text-xs', 'text-muted-foreground']">
                    {{ scorecard.costPerDeal.total }} spent since this team
                    started extracting, across
                    {{ formatCount(scorecard.costPerDeal.deals, 'deal') }}.
                </p>
            </div>
        </Card>

        <!--
            The quality question. Versions are the grouping, not a column, so
            "has a version change made it worse" is two rows to compare rather
            than a value buried down a list.
        -->
        <Card title="By model and prompt version">
            <EmptyState
                v-if="versions.length === 0"
                title="No model has run yet"
                description="Once an extraction reaches a provider, each model and prompt version gets its own row here so a change is visible as a change."
            />

            <div
                v-for="version in versions"
                :key="version.key"
                class="flex flex-col gap-1 border-b px-4 py-3 last:border-b-0"
                data-slot="version-row"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-13 font-medium text-foreground">{{
                        versionName(version)
                    }}</span>
                    <span :class="['text-xs', 'text-muted-foreground']"
                        >prompt
                        {{ version.promptVersion ?? 'unrecorded' }}</span
                    >
                </div>

                <p :class="['text-13', 'text-muted-foreground']">
                    <span
                        v-if="version.confirmedWithoutEdit !== null"
                        class="text-foreground"
                        data-slot="version-rate"
                        >{{ formatNumber(version.confirmedWithoutEdit) }}%
                        confirmed without edit</span
                    >
                    <span v-else data-slot="version-rate"
                        >No date proposals reviewed yet</span
                    >
                    <template v-if="version.reviewed > 0">
                        ({{ formatCount(version.reviewed, 'proposal') }})
                    </template>
                </p>

                <p :class="['text-xs', 'text-muted-foreground']">
                    {{ formatCount(version.attempts, 'extraction') }}
                    <template v-if="version.cost">
                        · {{ version.cost }}</template
                    >
                    <template v-if="version.lastUsedAt">
                        · last used {{ formatDateTime(version.lastUsedAt) }}
                    </template>
                </p>
            </div>
        </Card>

        <!-- The audit spine: every attempt, newest first, linking to S66. -->
        <Card title="Recent extractions">
            <EmptyState
                v-if="attempts.length === 0"
                title="No extractions yet"
                description="Reading a contract or an inspection report from a deal’s Documents tab records the attempt here — the document, the model, the cost, and who asked for it."
            />

            <div
                v-for="attempt in attempts"
                :key="attempt.id"
                class="flex flex-col gap-1.5 border-b px-4 py-3 last:border-b-0"
                data-slot="attempt-row"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge domain="extraction" :state="attempt.state" />
                    <span class="text-13 font-medium text-foreground">{{
                        attempt.dealName ?? 'Deal removed'
                    }}</span>
                    <span :class="['text-xs', 'text-muted-foreground']">{{
                        attempt.kindLabel
                    }}</span>
                </div>

                <p
                    v-if="attempt.documentName"
                    class="text-13 break-words text-foreground"
                >
                    {{ attempt.documentName }}
                </p>

                <p :class="['text-xs', 'text-muted-foreground']">
                    {{ attempt.model ?? 'no model recorded' }}
                    <template v-if="attempt.modelVersion">
                        · {{ attempt.modelVersion }}</template
                    >
                    · prompt {{ attempt.promptVersion ?? 'unrecorded' }}
                    <!--
                        A cost is shown only when the row was actually priced.
                        "$0.00" over a blocked or queued attempt reads as "this
                        was free", which is a different claim from "nothing has
                        been charged for this yet".
                    -->
                    <template v-if="attempt.cost">
                        · {{ attempt.cost }}</template
                    >
                </p>

                <p :class="['text-xs', 'text-muted-foreground']">
                    {{ formatCount(attempt.proposals, 'proposal') }}
                    <template v-if="attempt.pending > 0">
                        · {{ formatNumber(attempt.pending) }} still to
                        review</template
                    >
                    <template v-if="attempt.edited > 0">
                        · {{ formatNumber(attempt.edited) }} edited</template
                    >
                    <template v-if="attempt.requestedByName">
                        · {{ attempt.requestedByName }}</template
                    >
                    <template v-if="attempt.createdAt">
                        · {{ formatDateTime(attempt.createdAt) }}</template
                    >
                </p>

                <p
                    v-if="attempt.error"
                    :class="['text-xs', 'text-state-danger']"
                >
                    {{ attempt.error }}
                </p>

                <Link
                    v-if="attempt.url"
                    :href="attempt.url"
                    class="text-13 font-medium underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Open the review
                </Link>
            </div>

            <p
                v-if="attempts.length < attemptsTotal"
                :class="['px-4', 'py-2.5', 'text-xs', 'text-muted-foreground']"
                data-slot="attempts-cap"
            >
                Showing the {{ formatNumber(attempts.length) }} most recent of
                {{ formatCount(attemptsTotal, 'extraction') }}.
            </p>
        </Card>
    </div>
</template>
