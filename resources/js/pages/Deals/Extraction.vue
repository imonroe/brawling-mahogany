<script setup lang="ts">
/**
 * S66 **and** S67 — reviewing what a model proposed from a document
 * (PRD §4.10 F10.2–F10.4 · Design System §9.5 · issues #116, #117).
 *
 * ## One page, two screens, and that is deliberate
 *
 * Screen Inventory gives S66 (*Review extracted dates*) and S67 (*Review
 * extracted tasks*) **the same route** — `/deals/{deal}/extractions/
 * {extraction}` — because they are the same act over different rows: read
 * what the model copied, check it against the page it came from, and decide
 * one item at a time. So this page is discriminated by `extraction.kind`
 * rather than duplicated, and this paragraph exists because saying it in one
 * place is what stops the next person building the second copy. The Screen
 * Inventory row says it too; two statements of the rule is the cheap version
 * of a test for a thing no test can see.
 *
 * What actually differs between the kinds is one rule, and it is the one that
 * matters most — see *the primary action*, below.
 *
 * ## The layout is §9.5's P6 Split review, and it stacks
 *
 * Full-bleed review header (`h-16 px-6 border-b bg-card`), then a two-pane
 * split: source on the left at `w-[610px]` on `bg-muted` with its own 44px
 * toolbar, proposals on the right at `flex-1`, opening with a full-bleed
 * guard alert over a `p-4 gap-3` list of cards.
 *
 * Below `lg` the split stacks and the **source pane is dropped, not shrunk**.
 * A 610px pane on a phone is unusable, and the promise it exists to keep —
 * *the source is on screen next to the value* — is kept by the card instead:
 * §7.4's band 2 carries the verbatim quote, so the words the model copied sit
 * against the value it proposed at every width, and each card names its own
 * page. What the narrow layout loses is the larger typographic setting of the
 * passage — so the pane's one irreplaceable control, the link to the document
 * itself, is repeated above the list at `lg:hidden` rather than disappearing
 * with the pane that usually carries it.
 *
 * ## What the left pane is, honestly
 *
 * **It is not a PDF viewer.** This project has no renderer (Design System
 * §7.5 lists one as a *future* addition for S52 and S66), and the bytes are
 * on a private disk behind an audited route — so what is drawn on the page is
 * the **verbatim passage** the model was required to copy, with its page
 * number and a link to the document's own download route.
 *
 * That satisfies F10.2's *"showing the value, its confidence, and the source
 * page"* and §7.4's *"the source page link is mandatory on every field"*. It
 * does **not** satisfy §7.4's *"must jump the left pane to the highlighted
 * region"* of a rendered document: there is no rendered document, so there is
 * no region to highlight. Selecting a field moves this pane to that field's
 * passage, which is the honest version of the same gesture, and the gap is
 * recorded here rather than papered over — a reviewer who believes they are
 * looking at the contract when they are looking at a quotation *from* the
 * contract is exactly the failure this screen exists to prevent.
 *
 * ## The primary action is scoped, and it never confirms
 *
 * §7.4: *"There is no confirm-all, and no select-all. Each field is confirmed
 * individually."* §9.5 gives the header's primary action as `Confirm 3
 * reviewed dates`, **never** `Confirm all` — the count naming what a person
 * has already been through rather than what the model produced.
 *
 * On the **contract** kind there is therefore no bulk write at all: every
 * date is confirmed by its own press on its own card, and the header's
 * primary action reports the count and leads to Dates & Deadlines. It is
 * deliberately not *labelled* as a confirm, because it does not confirm
 * anything: a button reading "Confirm 3 reviewed dates" that posted nothing
 * would be a lie about the most consequential surface in the product.
 *
 * On the **inspection** kind #117 allows a bulk accept — *"an unwanted task
 * is an annoyance, not a legal exposure"* — over findings a person has
 * explicitly ticked, and it still says how many. The tick is the only
 * difference between the two screens, and a date never renders one.
 *
 * ## Provenance is a requirement, not a footer
 *
 * F10.4 stores the model, its version, the prompt version and the cost, and
 * §9.5 puts them in the header as a 12px muted line. It is there so a
 * reviewer can tell *which* model said this and what it cost to ask, and so
 * the answer to "why is this list different from last month's" is on the
 * screen rather than in a database.
 */
import { Head, router } from '@inertiajs/vue3';
import {
    Ban,
    FileSearch,
    Hourglass,
    LoaderCircle,
    ShieldAlert,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import ExtractionReviewCard from '@/components/app/ExtractionReviewCard.vue';
import type { ExtractedFieldRow } from '@/components/app/ExtractionReviewCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatCount, formatNumber } from '@/lib/formatters';

const props = defineProps<{
    dealHeader: DealHeaderProps;
    dealUrl: string;
    extraction: {
        id: string;
        kind: 'contract' | 'inspection';
        kindLabel: string;
        state: 'queued' | 'processing' | 'complete' | 'failed' | 'blocked';
        documentName: string;
        /** The document's own audited download route (PRD §9). Never a presigned URL. */
        documentUrl: string | null;
        provenance: {
            provider: string | null;
            model: string | null;
            modelVersion: string | null;
            promptVersion: string | null;
            /** Already words — "$0.08". Composed on the server, like every money string. */
            cost: string | null;
            latencyMs: number | null;
        };
        error: string | null;
        /** Inspection only: findings the model left out of this list. */
        omittedCount: number | null;
    };
    fields: ExtractedFieldRow[];
    progress: { reviewed: number; total: number };
    canConfirm: boolean;
}>();

const isInspection = computed(() => props.extraction.kind === 'inspection');

/* -------------------------------------------------------------------------
 * Selection — which passage the source pane is showing
 * ---------------------------------------------------------------------- */

const selectedId = ref<string | null>(props.fields[0]?.id ?? null);
const sourcePane = ref<HTMLElement | null>(null);

const selected = computed(
    () => props.fields.find((field) => field.id === selectedId.value) ?? null,
);

/*
 * A partial reload can replace the list — a confirmed field comes back with a
 * new state, a rejected one may leave it. A selection pointing at a row that
 * is gone renders an empty pane beside a full list, which reads as "there is
 * no source for this" rather than as "you are not looking at anything".
 */
watch(
    () => props.fields.map((field) => field.id).join(','),
    () => {
        if (!props.fields.some((field) => field.id === selectedId.value)) {
            selectedId.value = props.fields[0]?.id ?? null;
        }
    },
);

/**
 * Show a field's passage — §7.4's *"jump the left pane"*, as far as a quoted
 * passage can be jumped to.
 *
 * The scroll is on the pane rather than on the window: the passage is short
 * and the pane is the thing that moved, and scrolling the document would take
 * the card being reviewed off the screen.
 *
 * `scrollTop`, not `scrollTo({ behavior: 'smooth' })` — the easing belongs to
 * the pane's own `scroll-smooth`, which honours a reader's
 * `prefers-reduced-motion` where a scripted behaviour does not, and
 * `Element.scrollTo` is not implemented everywhere this code runs (jsdom is
 * the one that said so out loud).
 */
function show(id: string): void {
    selectedId.value = id;

    const pane = sourcePane.value;

    if (pane) {
        pane.scrollTop = 0;
    }
}

/* -------------------------------------------------------------------------
 * The scoped bulk accept (inspection only, #117)
 * ---------------------------------------------------------------------- */

const tickedIds = ref<string[]>([]);

const tickable = computed(() =>
    props.fields.filter(
        (field) => field.reviewState === 'pending' && isInspection.value,
    ),
);

/*
 * Ticks follow the list. A ticked finding that has since been accepted or has
 * left the list must not stay in the array, or the count above the list would
 * name rows the post could not touch — the badge-counts-a-different-set
 * defect CLAUDE.md records against S57, one screen along.
 */
watch(
    () => tickable.value.map((field) => field.id).join(','),
    () => {
        const live = new Set(tickable.value.map((field) => field.id));

        tickedIds.value = tickedIds.value.filter((id) => live.has(id));
    },
);

function tick(id: string, on: boolean): void {
    tickedIds.value = on
        ? [...new Set([...tickedIds.value, id])]
        : tickedIds.value.filter((ticked) => ticked !== id);
}

function acceptTicked(): void {
    if (tickedIds.value.length === 0) {
        return;
    }

    router.post(
        `${props.dealUrl}/extractions/${props.extraction.id}/fields`,
        { ids: tickedIds.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                tickedIds.value = [];
            },
        },
    );
}

/* -------------------------------------------------------------------------
 * The per-field writes — the only way anything reaches the deal
 * ---------------------------------------------------------------------- */

function confirmField(field: ExtractedFieldRow, value: string): void {
    router.post(
        `${props.dealUrl}/extractions/${props.extraction.id}/fields/${field.id}`,
        { value },
        { preserveScroll: true },
    );
}

function rejectField(field: ExtractedFieldRow): void {
    router.delete(
        `${props.dealUrl}/extractions/${props.extraction.id}/fields/${field.id}`,
        { preserveScroll: true },
    );
}

/* -------------------------------------------------------------------------
 * The header
 * ---------------------------------------------------------------------- */

const progressLabel = computed(
    () => `${props.progress.reviewed} of ${props.progress.total} reviewed`,
);

/**
 * F10.4's audit line, as words.
 *
 * Nulls are dropped rather than rendered as blanks, and an entirely empty
 * provenance says so: an extraction that has not run yet has no model, and a
 * silent line would read as one that did.
 */
const provenanceLabel = computed((): string => {
    const { provider, model, modelVersion, promptVersion, cost, latencyMs } =
        props.extraction.provenance;

    const engine = [provider, model, modelVersion].filter(Boolean).join(' ');

    const parts = [
        engine === '' ? null : engine,
        promptVersion === null ? null : `prompt ${promptVersion}`,
        cost,
        latencyMs === null ? null : `${formatNumber(latencyMs)}ms`,
    ].filter((part): part is string => part !== null);

    return parts.length === 0
        ? 'No model has been recorded for this extraction yet.'
        : parts.join(' · ');
});

/**
 * The header's primary action.
 *
 * Scoped, in both kinds, and never a confirm-all. See the header comment for
 * why the contract kind's leads away rather than posting.
 */
const primary = computed(() => {
    if (isInspection.value) {
        return tickedIds.value.length === 0
            ? { label: 'Accept ticked findings', enabled: false, href: null }
            : {
                  label: `Accept ${formatCount(tickedIds.value.length, 'ticked finding')}`,
                  enabled: true,
                  href: null,
              };
    }

    return props.progress.reviewed === 0
        ? { label: 'Nothing reviewed yet', enabled: false, href: null }
        : {
              label: `Done — ${formatCount(props.progress.reviewed, 'date')} reviewed`,
              enabled: true,
              href: `${props.dealUrl}/dates`,
          };
});
</script>

<template>
    <Head :title="`${extraction.kindLabel} — ${dealHeader.name}`" />

    <!--
        §9.2: the DealHeader above is full-bleed and this page is too — P6's
        review header sits directly under it, so there is no `p-6` here.
    -->
    <div class="flex min-h-0 flex-1 flex-col">
        <!-- §9.5's review header. -->
        <header
            class="flex min-h-16 flex-wrap items-center gap-3 border-b bg-card px-6 py-3"
        >
            <div class="flex min-w-0 flex-col">
                <div class="flex items-center gap-2">
                    <h2 class="truncate text-sm font-semibold">
                        {{ extraction.kindLabel }}
                    </h2>
                    <span class="truncate text-13 text-muted-foreground">{{
                        extraction.documentName
                    }}</span>
                </div>
                <!-- F10.4. Required, not decorative. -->
                <p class="text-xs text-muted-foreground">
                    {{ provenanceLabel }}
                </p>
            </div>

            <div class="flex-1"></div>

            <StatusBadge
                v-if="extraction.state === 'complete'"
                tone="neutral"
                :label="progressLabel"
                dotless
            />
            <!--
                Everything before `complete` is a real state with its own
                words below; the badge is the one-glance version of it, and it
                reads its tone from `lib/states.ts` like every other badge.
            -->
            <StatusBadge
                v-else
                domain="extraction"
                :state="extraction.state"
                dotless
            />

            <AppButton variant="secondary" :href="dealUrl">
                Back to the deal
            </AppButton>

            <!--
                Two buttons, not one with a conditional handler: the
                inspection kind's primary *posts* (#117's scoped accept) and
                the contract kind's *leads away*, and a control that is
                sometimes a link and sometimes a form submit is the shape
                somebody later wires the wrong half of.
            -->
            <AppButton
                v-if="
                    extraction.state === 'complete' &&
                    canConfirm &&
                    isInspection
                "
                :disabled="!primary.enabled"
                @click="acceptTicked"
            >
                {{ primary.label }}
            </AppButton>
            <AppButton
                v-else-if="extraction.state === 'complete' && canConfirm"
                :href="primary.href ?? undefined"
                :disabled="!primary.enabled"
            >
                {{ primary.label }}
            </AppButton>
        </header>

        <!-- Queued / processing / failed / blocked: real states, not a spinner. -->
        <div
            v-if="extraction.state !== 'complete'"
            class="flex flex-1 flex-col p-6"
        >
            <Card class="p-0">
                <EmptyState
                    v-if="extraction.state === 'queued'"
                    :icon="Hourglass"
                    title="Waiting its turn"
                    description="This document is in the queue. You can leave this page — the extraction carries on without you, and it will be here when you come back."
                />

                <EmptyState
                    v-else-if="extraction.state === 'processing'"
                    :icon="LoaderCircle"
                    title="Reading the document"
                    description="This usually takes a couple of minutes. You can leave this page; nothing is lost if you do, and nothing reaches the deal until you have reviewed it."
                />

                <EmptyState
                    v-else-if="extraction.state === 'failed'"
                    :icon="TriangleAlert"
                    title="This extraction did not finish"
                    :description="
                        extraction.error ??
                        'The extraction stopped before it produced anything, and no reason was recorded.'
                    "
                >
                    <template #action>
                        <!--
                            Starting one takes the document, which is what the
                            Documents tab holds — so "try again" goes to the
                            place an extraction is actually begun rather than
                            re-posting from a screen whose own document is
                            already spent. Nothing was written, so there is
                            nothing to undo first.
                        -->
                        <AppButton :href="`${dealUrl}/documents`">
                            Try again from Documents
                        </AppButton>
                    </template>
                </EmptyState>

                <EmptyState
                    v-else
                    :icon="Ban"
                    title="Extraction is paused for this month"
                    :description="
                        extraction.error ??
                        'This team has reached the amount it is willing to spend on extraction this month.'
                    "
                >
                    <template #action>
                        <!--
                            Deliberately no retry. A spend cap is a decision
                            somebody made, not a fault to press through, and a
                            Try again button beside it would be an invitation
                            to spend past it. The dates can be added by hand in
                            the meantime, which is what this links to.
                        -->
                        <AppButton
                            variant="secondary"
                            :href="`${dealUrl}/dates`"
                        >
                            Add the dates by hand
                        </AppButton>
                    </template>
                </EmptyState>
            </Card>
        </div>

        <!-- §9.5's two-pane split. Stacks below `lg`; see the header comment. -->
        <div v-else class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <!-- Left, source. -->
            <section
                class="hidden flex-col border-r bg-muted lg:flex lg:w-[610px] lg:shrink-0"
                aria-label="Source passage"
            >
                <div
                    class="flex h-11 shrink-0 items-center gap-2 border-b px-4"
                >
                    <FileSearch
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="truncate text-13 font-medium">{{
                        extraction.documentName
                    }}</span>
                    <div class="flex-1"></div>
                    <!--
                        A plain anchor, not an Inertia `Link`: this is the
                        document's own audited download route (PRD §9), and one
                        path to the bytes means the entry is written whichever
                        screen asked.
                    -->
                    <a
                        v-if="extraction.documentUrl"
                        :href="extraction.documentUrl"
                        class="shrink-0 text-[11px] font-semibold text-primary hover:underline"
                    >
                        Open the document
                    </a>
                </div>

                <div
                    ref="sourcePane"
                    class="min-h-0 flex-1 overflow-y-auto scroll-smooth p-6"
                >
                    <article
                        v-if="selected"
                        class="mx-auto flex max-w-[520px] flex-col gap-3 rounded-md border bg-card p-6"
                        data-slot="source-page"
                    >
                        <p
                            class="text-[11px] font-semibold text-muted-foreground"
                        >
                            {{
                                selected.sourcePage === null
                                    ? 'No page recorded'
                                    : `Page ${selected.sourcePage}`
                            }}
                        </p>

                        <blockquote
                            v-if="selected.sourceSnippet"
                            class="text-sm leading-relaxed text-foreground"
                        >
                            {{ selected.sourceSnippet }}
                        </blockquote>
                        <p v-else class="text-sm text-state-danger">
                            Nothing was copied from the document for
                            {{ selected.label }}. Open the document and read it
                            before you confirm anything here.
                        </p>

                        <!--
                            Said on the screen, not only in a docblock. A
                            reviewer who thinks this is the contract is being
                            told it is a quotation from it — which is the
                            difference between checking a document and
                            checking a model's copy of one.
                        -->
                        <p
                            class="border-t pt-3 text-[11px] text-muted-foreground"
                        >
                            This is the passage the model copied, not the page
                            itself. Open the document to see it in context.
                        </p>
                    </article>

                    <p v-else class="text-13 text-muted-foreground">
                        Choose a proposal on the right to see the words it came
                        from.
                    </p>
                </div>
            </section>

            <!-- Right, proposals. -->
            <section
                class="flex min-h-0 flex-1 flex-col lg:overflow-y-auto"
                aria-label="Proposals"
            >
                <!--
                    §9.5's full-bleed guard alert, in §7.4's PII-warning
                    anatomy. It is the S66 danger note made visible: nothing
                    here is on the deal, and a confidence mark is not a second
                    reader.
                -->
                <div
                    class="flex shrink-0 items-start gap-2.5 border-b border-state-warning bg-state-warning-bg px-4 py-3"
                    data-slot="review-guard"
                >
                    <ShieldAlert
                        class="mt-0.5 size-4 shrink-0 text-state-warning"
                        aria-hidden="true"
                    />
                    <div class="flex flex-col gap-1">
                        <p class="text-13 font-semibold text-state-warning">
                            Nothing on this page is on the deal yet.
                        </p>
                        <p class="text-xs text-secondary-foreground">
                            <template v-if="isInspection">
                                A task is created when you accept it — one at a
                                time, or the ones you tick. The model was asked
                                to copy what the report says; it did not inspect
                                anything.
                            </template>
                            <template v-else>
                                A date reaches this deal's calendar when you
                                confirm it, one at a time, against the passage
                                it came from. A high confidence mark is the
                                model's opinion of its own copying — not a
                                second reader, and not permission to skip one.
                            </template>
                        </p>
                        <p
                            v-if="isInspection && extraction.omittedCount"
                            class="text-xs font-medium text-state-warning"
                        >
                            The model left
                            {{
                                formatCount(extraction.omittedCount, 'finding')
                            }}
                            out of this list. Read the report before you decide
                            the list is complete.
                        </p>
                    </div>
                </div>

                <!--
                    The one control the stacked layout would otherwise lose
                    with the pane. A reviewer on a phone still has to be able
                    to open the document, because the card carries the quote
                    and the quote is exactly the thing they may want to check.
                -->
                <div
                    v-if="extraction.documentUrl"
                    class="flex shrink-0 items-center gap-2 border-b px-4 py-2.5 lg:hidden"
                >
                    <FileSearch
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="truncate text-13 text-muted-foreground">{{
                        extraction.documentName
                    }}</span>
                    <div class="flex-1"></div>
                    <a
                        :href="extraction.documentUrl"
                        class="shrink-0 text-[11px] font-semibold text-primary hover:underline"
                    >
                        Open the document
                    </a>
                </div>

                <div class="flex flex-col gap-3 p-4">
                    <EmptyState
                        v-if="fields.length === 0"
                        :icon="FileSearch"
                        title="The model found nothing to propose"
                        :description="
                            isInspection
                                ? 'No findings were pulled out of this report. Add the tasks by hand, or try a clearer copy of the document.'
                                : 'No dates were pulled out of this document. Add them by hand on Dates & Deadlines, or try a clearer copy.'
                        "
                    >
                        <template #action>
                            <AppButton
                                variant="secondary"
                                :href="
                                    isInspection
                                        ? `${dealUrl}/tasks`
                                        : `${dealUrl}/dates`
                                "
                            >
                                {{
                                    isInspection
                                        ? 'Go to Tasks'
                                        : 'Go to Dates & Deadlines'
                                }}
                            </AppButton>
                        </template>
                    </EmptyState>

                    <ExtractionReviewCard
                        v-for="field in fields"
                        :key="field.id"
                        :field="field"
                        :selected="field.id === selectedId"
                        :can-confirm="canConfirm"
                        :tickable="isInspection"
                        :ticked="tickedIds.includes(field.id)"
                        @select="show(field.id)"
                        @confirm="(value) => confirmField(field, value)"
                        @reject="rejectField(field)"
                        @undo="show(field.id)"
                        @update:ticked="(on) => tick(field.id, on)"
                    />
                </div>
            </section>
        </div>
    </div>
</template>
