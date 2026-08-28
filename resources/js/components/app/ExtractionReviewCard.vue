<script setup lang="ts">
/**
 * The extraction review card — Design System §7.4, S66 and S67 (PRD §4.10
 * F10.2, F10.3, F10.4 · issues #116, #117).
 *
 * §7.4 calls this *"the highest-risk component in the product"* and gives it
 * three rules that are not negotiable and not softenable:
 *
 * > - **There is no confirm-all, and no select-all.** Each field is confirmed
 * >   individually.
 * > - **The source page link is mandatory** on every field, and must jump the
 * >   left pane to the highlighted region.
 * > - **A conflict with an existing date must state the consequence**
 * >   ("shifts 4 derived deadlines"), not merely flag a difference.
 *
 * All three live here rather than in the page, because the page is the thing
 * a later slice rewrites and the card is the thing every extracted value
 * passes through. `tests/js/extractionReview.test.ts` mounts this component —
 * not a copy of its arithmetic — for the reason CLAUDE.md records against
 * `calendarNavigation.test.ts`: a guard that re-implements what it guards
 * stays green with the fix deleted.
 *
 * ## Four bands, in §7.4's order
 *
 * 1. label · confidence · source page link
 * 2. the value, and the verbatim quote it was copied from
 * 3. *(conditional)* the conflict strip, and what confirming would move
 * 4. Reject / Edit / Confirm — or, once reviewed, the state, who reviewed it,
 *    when, and Undo
 *
 * ## Confirm is always a press, however sure the model was
 *
 * F10.2: *"Nothing enters the contingency calendar unconfirmed."* There is no
 * threshold above which this card confirms itself, no default tick, and no
 * keyboard shortcut that would let a held return key walk a list. A 0.99 and
 * a 0.4 present identically except for the mark, which is what §2.5 means by
 * confidence being information rather than permission.
 *
 * ## Undo re-opens; it does not silently unwind
 *
 * A reviewed field's Undo puts the action band back rather than posting
 * anything. That is what the endpoints actually support and it is also the
 * honest shape: a confirmed date is *on the deal*, so taking it back off is
 * Reject — a real write with a real consequence — and changing it is Confirm
 * again with a corrected value, which is what F10.4's *"what the human
 * changed"* record is for. A control labelled Undo that quietly deleted a
 * live deadline would be the worst button on the screen.
 *
 * ## The tick is S67's, and it is not a select-all
 *
 * #117 allows a bulk accept on the *inspection* kind — *"an unwanted task is
 * an annoyance, not a legal exposure"* — over items a person has explicitly
 * ticked. `tickable` is false on the contract kind, so a date has no tick to
 * offer and the page has nothing to select-all over.
 *
 * ## Selecting is a keyboard gesture as well as a pointer one
 *
 * `focusin` selects, so tabbing through the list moves the source pane
 * without a mouse. The click is the same intent from the other input device;
 * neither is the *only* way to reach the passage, because the page link in
 * band 1 is a real button.
 *
 * (This prose is here and not above the root element on purpose: a comment
 * node beside the root makes the component a fragment and attribute
 * inheritance silently stops.)
 */
import {
    CalendarClock,
    Check,
    FileSearch,
    GitCompareArrows,
    Pencil,
    TriangleAlert,
    Undo2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppTextarea from '@/components/app/AppTextarea.vue';
import ConfidenceMark from '@/components/app/ConfidenceMark.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatCount, formatDate, formatDateTime } from '@/lib/formatters';
import type { Tone } from '@/lib/states';
import { cn } from '@/lib/utils';

/**
 * One proposal, exactly as the server ships it.
 *
 * Exported from the component that draws it (the shape `KeyDateFormDialog`
 * set with `KeyDateRow`): the page importing this type is importing it from
 * the thing that decides what each member means.
 */
export type ExtractedFieldRow = {
    id: string;
    fieldType: 'key_date' | 'provision' | 'task';
    label: string;
    /** What the model proposed, kept whatever a person types over it (F10.4). */
    proposedValue: string;
    /** What would be written today — the proposal, or the human's correction. */
    value: string;
    /** 0..1, or null when the model reported none. */
    confidence: number | null;
    sourcePage: number | null;
    sourceSnippet: string | null;
    reviewState: 'pending' | 'confirmed' | 'edited' | 'rejected';
    reviewedByName: string | null;
    /** ISO instant. */
    reviewedAt: string | null;
    isCritical: boolean;
    /** "MEC + 10 days" — a date that was worked out rather than read. */
    derivation: string | null;
    /** The body of a proposed task (S67). */
    detail: string | null;
    severity: 'safety' | 'material' | 'minor' | null;
    conflict: { name: string; currentDate: string; movesCount: number } | null;
    cascade: {
        id: string;
        name: string;
        isCritical: boolean;
        from: string;
        to: string;
        days: number;
    }[];
    /** Set once the field has been confirmed and a row exists to link to. */
    createdRecordUrl: string | null;
};

const props = withDefaults(
    defineProps<{
        field: ExtractedFieldRow;
        /** Whether the source pane is showing this field's passage. */
        selected?: boolean;
        /** False hides the whole action band rather than disabling it (§9.7). */
        canConfirm?: boolean;
        /** S67 only: whether this finding is in the scoped bulk accept. */
        ticked?: boolean;
        /** S67 only. Never true on the contract kind — see the header. */
        tickable?: boolean;
    }>(),
    { selected: false, canConfirm: true, ticked: false, tickable: false },
);

const emit = defineEmits<{
    /** Show this field's passage in the source pane. */
    select: [];
    /** Confirm, carrying the value as it stands — edited or not. */
    confirm: [value: string];
    reject: [];
    undo: [];
    'update:ticked': [value: boolean];
}>();

const editing = ref(false);
const draft = ref(props.field.value);

/**
 * Whether the action band is showing over a field that has already been
 * reviewed — the state Undo puts it in.
 *
 * Local, because it is a thing about this card on this screen right now and
 * not a thing about the row: a reload lands on the server's answer, which is
 * the reviewed state it actually holds.
 */
const reopened = ref(false);

/*
 * A reviewed row that comes back changed resets everything: the draft, the
 * edit, and the re-open. Without this, confirming a corrected value left the
 * card sitting on the stale draft, and the next press would have written the
 * old text back over the new one.
 */
watch(
    () => [props.field.id, props.field.value, props.field.reviewState],
    () => {
        draft.value = props.field.value;
        editing.value = false;
        reopened.value = false;
    },
);

const reviewed = computed(() => props.field.reviewState !== 'pending');
const showActions = computed(
    () => props.canConfirm && (!reviewed.value || reopened.value),
);

/**
 * The value as a person reads it.
 *
 * `formatDate` throws on something that is not a date, and *something that is
 * not a date* is exactly what an extraction can propose — this is a model's
 * copy of a contract, not a validated column. Falling back to the raw string
 * shows the reviewer the thing they have to judge; throwing would take the
 * whole review screen down over one bad row.
 */
const displayValue = computed((): string => {
    if (props.field.fieldType !== 'key_date' || props.field.value === '') {
        return props.field.value;
    }

    try {
        return formatDate(props.field.value);
    } catch {
        return props.field.value;
    }
});

/** True once a person has typed over what the model proposed (F10.4). */
const wasCorrected = computed(
    () => props.field.value !== props.field.proposedValue,
);

/**
 * What confirming this would do to the dates already on the deal.
 *
 * §7.4's third rule, and the only string on the card that is composed rather
 * than shipped: a difference is not a consequence, and *"shifts 4 derived
 * deadlines"* is the half that lets somebody decide. `formatCount` owns the
 * pluralisation (Frontend conventions §3), and the zero case says what is
 * true rather than saying nothing — a conflict that moves nothing is still a
 * date being replaced.
 */
const consequence = computed((): string | null => {
    const conflict = props.field.conflict;

    if (conflict === null) {
        return null;
    }

    const replaced = `${conflict.name} is already set to ${formatDate(conflict.currentDate)}. Confirming this replaces it`;

    return conflict.movesCount === 0
        ? `${replaced}. Nothing else is counted from it.`
        : `${replaced} and shifts ${formatCount(conflict.movesCount, 'derived deadline')}.`;
});

const attribution = computed((): string | null => {
    if (props.field.reviewedAt === null) {
        return props.field.reviewedByName;
    }

    const when = formatDateTime(props.field.reviewedAt);

    return props.field.reviewedByName === null
        ? when
        : `${props.field.reviewedByName} · ${when}`;
});

/**
 * An inspection finding's severity, as a one-off pill.
 *
 * `StatusBadge`'s bare `tone` form, which §7.2 keeps *"for counts and one-off
 * pills"* — the same call `Deals/Dates.vue` makes for **Critical**. Severity
 * is not a state: nothing transitions between these, no evaluator reads them,
 * and there is no `severity` domain in `lib/states.ts` to resolve. If a later
 * slice gives it a lifecycle, it earns a row in Design System §2.4 and a table
 * here, in that order (§13.2 rule 7).
 *
 * `safety` is the one red on this card, and it is red for §2.4's actual
 * reason: an inspection finding about a gas line is something that is broken.
 */
const SEVERITY: Record<string, { tone: Tone; label: string }> = {
    safety: { tone: 'danger', label: 'Safety' },
    material: { tone: 'warning', label: 'Material' },
    minor: { tone: 'neutral', label: 'Minor' },
};

const severity = computed(() =>
    props.field.severity === null ? null : SEVERITY[props.field.severity],
);

function startEdit(): void {
    draft.value = props.field.value;
    editing.value = true;
    emit('select');
}

function cancelEdit(): void {
    draft.value = props.field.value;
    editing.value = false;
}

function confirm(): void {
    emit('confirm', editing.value ? draft.value : props.field.value);
    editing.value = false;
}

function undo(): void {
    reopened.value = true;
    emit('undo');
    emit('select');
}
</script>

<template>
    <article
        :class="
            cn(
                'flex flex-col gap-2.5 rounded-lg border bg-card p-3.5',
                field.conflict && 'border-state-warning',
                selected && 'border-[1.5px] border-primary',
            )
        "
        data-slot="extraction-review-card"
        :data-review-state="field.reviewState"
        :data-selected="selected"
        @click="emit('select')"
        @focusin="emit('select')"
    >
        <!-- Band 1 — label, confidence, and the way back to the page it came from. -->
        <div class="flex flex-wrap items-center gap-2">
            <!--
                #117's tick, and only on the inspection kind. A date never
                renders one, which is what makes "no select-all on dates" a
                property of the markup rather than a rule somebody remembers.
            -->
            <input
                v-if="tickable && canConfirm && !reviewed"
                type="checkbox"
                class="size-4 shrink-0 accent-primary"
                :checked="ticked"
                :aria-label="`Include ${field.label} in the findings you accept together`"
                @click.stop
                @change="
                    emit(
                        'update:ticked',
                        ($event.target as HTMLInputElement).checked,
                    )
                "
            />

            <span class="text-13 font-semibold">{{ field.label }}</span>

            <StatusBadge
                v-if="field.isCritical"
                tone="warning"
                label="Critical"
                dotless
            />
            <StatusBadge
                v-if="severity"
                :tone="severity.tone"
                :label="severity.label"
                dotless
            />

            <div class="flex-1"></div>

            <ConfidenceMark :confidence="field.confidence" />

            <!--
                §7.4's second rule. A page number is mandatory, so when the
                extraction did not record one the card says so in the danger
                tone rather than drawing a link to nowhere — the reviewer is
                the fallback, and they need to know they are it.
            -->
            <button
                v-if="field.sourcePage !== null"
                type="button"
                class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline"
                @click.stop="emit('select')"
            >
                <FileSearch class="size-3.5" aria-hidden="true" />
                Page {{ field.sourcePage }}
            </button>
            <span
                v-else
                class="inline-flex items-center gap-1 text-[11px] font-semibold text-state-danger"
            >
                <TriangleAlert class="size-3.5" aria-hidden="true" />
                No source page — check the document yourself
            </span>
        </div>

        <!-- Band 2 — the value, beside the words it was copied from. -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:gap-3">
            <div class="shrink-0">
                <template v-if="editing">
                    <AppTextarea
                        v-if="field.fieldType === 'provision'"
                        v-model="draft"
                        :rows="3"
                        class="w-full sm:w-[280px]"
                        :aria-label="`${field.label} — corrected value`"
                    />
                    <AppInput
                        v-else
                        v-model="draft"
                        :type="field.fieldType === 'key_date' ? 'date' : 'text'"
                        class="w-full sm:w-[220px]"
                        :aria-label="`${field.label} — corrected value`"
                    />
                </template>

                <p
                    v-else
                    class="flex min-h-[34px] items-center gap-2 rounded-md border px-2.5 sm:w-[170px]"
                >
                    <CalendarClock
                        v-if="field.fieldType === 'key_date'"
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="tabular text-13 font-semibold">{{
                        displayValue
                    }}</span>
                </p>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <!--
                    The verbatim quote — §7.4's band 2, and the thing that
                    makes "the source is next to the value" true on a phone as
                    well as beside the source pane.
                -->
                <blockquote
                    v-if="field.sourceSnippet"
                    class="border-l-2 pl-2.5 text-xs text-muted-foreground"
                >
                    {{ field.sourceSnippet }}
                </blockquote>
                <p v-else class="text-xs text-state-danger">
                    No passage was copied for this field. Read it in the
                    document before you confirm it.
                </p>

                <p
                    v-if="field.detail"
                    class="text-xs text-secondary-foreground"
                >
                    {{ field.detail }}
                </p>

                <!--
                    A derived date was never on the page, and saying so is the
                    difference between checking a quote and checking a sum.
                -->
                <p
                    v-if="field.derivation"
                    class="text-[11px] text-muted-foreground"
                >
                    Worked out as {{ field.derivation }} — not copied from the
                    page.
                </p>

                <p
                    v-if="wasCorrected"
                    class="text-[11px] text-muted-foreground"
                >
                    The model proposed “{{ field.proposedValue }}”.
                </p>
            </div>
        </div>

        <!-- Band 3 — the conflict, and what confirming would move. -->
        <div
            v-if="consequence"
            class="flex items-start gap-2 rounded-sm bg-state-warning-bg p-2.5"
        >
            <GitCompareArrows
                class="mt-px size-4 shrink-0 text-state-warning"
                aria-hidden="true"
            />
            <div class="flex min-w-0 flex-col gap-1">
                <p class="text-[11px] font-semibold text-state-warning">
                    {{ consequence }}
                </p>
                <ul
                    v-if="field.cascade.length > 0"
                    class="flex flex-col gap-0.5"
                >
                    <li
                        v-for="move in field.cascade"
                        :key="move.id"
                        class="tabular text-[11px] text-state-warning"
                    >
                        {{ move.name
                        }}{{ move.isCritical ? ' (critical)' : '' }}:
                        {{ formatDate(move.from) }} →
                        {{ formatDate(move.to) }}
                    </li>
                </ul>
            </div>
        </div>

        <!-- Band 4 — the decision, or the record of one. -->
        <div v-if="showActions" class="flex flex-wrap items-center gap-2">
            <p
                v-if="reopened"
                class="text-[11px] text-muted-foreground"
                data-slot="reopened-note"
            >
                This is already on the deal. Confirm again to change it, or
                Reject to take it off.
            </p>

            <div class="flex-1"></div>

            <template v-if="editing">
                <AppButton
                    variant="ghost"
                    size="compact"
                    @click.stop="cancelEdit"
                >
                    <X class="size-4" />
                    Cancel
                </AppButton>
                <AppButton size="compact" @click.stop="confirm">
                    <Check class="size-4" />
                    Confirm
                </AppButton>
            </template>

            <template v-else>
                <AppButton
                    variant="ghost"
                    size="compact"
                    @click.stop="emit('reject')"
                >
                    Reject
                </AppButton>
                <AppButton
                    variant="secondary"
                    size="compact"
                    @click.stop="startEdit"
                >
                    <Pencil class="size-4" />
                    Edit
                </AppButton>
                <!--
                    One press, one field. There is no confirm-all above this
                    list and no threshold below it — §7.4, and F10.2.
                -->
                <AppButton size="compact" @click.stop="confirm">
                    <Check class="size-4" />
                    Confirm
                </AppButton>
            </template>
        </div>

        <div v-else-if="reviewed" class="flex flex-wrap items-center gap-2">
            <StatusBadge
                domain="extractedField"
                :state="field.reviewState"
                dotless
            />
            <span
                v-if="attribution"
                class="text-[11px] text-muted-foreground"
                >{{ attribution }}</span
            >
            <a
                v-if="field.createdRecordUrl"
                :href="field.createdRecordUrl"
                class="text-[11px] font-semibold text-primary hover:underline"
                @click.stop
            >
                Open it on the deal
            </a>

            <div class="flex-1"></div>

            <AppButton
                v-if="canConfirm"
                variant="ghost"
                size="compact"
                @click.stop="undo"
            >
                <Undo2 class="size-4" />
                Undo
            </AppButton>
        </div>

        <!--
            §9.7's permission-denied state, said once per card rather than as
            eleven disabled buttons: a greyed control only invites the
            question (Frontend conventions §4).
        -->
        <p v-else class="text-[11px] text-muted-foreground">
            Somebody with permission to change this deal has to review this.
        </p>
    </article>
</template>
