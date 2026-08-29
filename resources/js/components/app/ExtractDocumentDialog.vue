<script setup lang="ts">
/**
 * S65 — starting an extraction from a document (PRD §4.10 F10.1, F10.3,
 * F10.5 · §14.3 · Design System §8.9).
 *
 * ## What this dialog is actually asking permission for
 *
 * PRD §4.10 carries a danger note headed *the unresolved tension*:
 *
 * > Emily wants AI extraction **and** wants to limit what the AI sees, and
 * > both instincts are right. Contracts contain exactly the personal
 * > financial information she is worried about. **F10.5 narrows the exposure.
 * > It does not eliminate it.**
 *
 * And §14.3, on uploads: *"Mitigated, not eliminated. Do not let marketing
 * copy claim more than section 8.4 actually delivers."*
 *
 * So the dialog says the true thing in the smallest number of words: the
 * document's words are read, financial identifiers are masked first, and what
 * is left is sent to a company that is not this one. It does **not** say
 * "securely", it does not say "anonymised", and it does not say the data
 * never leaves the account — because it does. A person who later discovers
 * that a contract went to an outside provider must not be able to say this
 * screen implied otherwise; that is a legal question as much as a copy one,
 * and it is Heather's brokerage that has to have the clause.
 *
 * ## The cost warning is a state, not a footnote
 *
 * Screen Inventory gives S65 a *cost warning* among its key states, so `spend`
 * is a prop rather than a line of static copy: what a person needs is where
 * this team is against its own cap, before they spend more of it. `warn` is
 * the server's judgement of when that becomes a warning rather than a
 * reading, so one rule decides it and every surface agrees.
 *
 * ## Unavailable is a state too, with a reason
 *
 * §9.7 asks every screen for its error and permission-denied states. `false`
 * on `available` is not a disabled button with no explanation — no provider
 * configured, a spend cap already reached and a document that cannot be read
 * are three different sentences, and the server sends which.
 *
 * ## The word is Extract
 *
 * IA §11 bans Scan, Parse, Analyze, Read and AI as names for this feature.
 * The *mechanism* is still described in plain words below, because refusing
 * to name the mechanism is how a disclosure becomes marketing.
 */
import { useForm } from '@inertiajs/vue3';
import { CircleDollarSign, ShieldAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    open: boolean;
    documentId: string;
    documentName: string;
    dealUrl: string;
    /** False when no provider is configured for this environment. */
    available: boolean;
    unavailableReason: string | null;
    spend: {
        /** Already words — "$4.80". Money is composed on the server. */
        used: string;
        /**
         * Null when there is no ceiling at all.
         *
         * `SpendLedger` reads a **negative** cap as the absence of one and zero
         * as a ceiling of zero; the server collapses that to *"a figure or
         * nothing"* so this component and S68 draw the same thing. It shipped
         * as a plain string for two rounds, which drew a negative dollar amount
         * on an uncapped installation.
         */
        cap: string | null;
        percent: number | null;
        warn: boolean;
        /** When the month's allowance starts again, as words. */
        resetsAt: string;
    };
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({
    documentId: props.documentId,
    kind: 'contract' as 'contract' | 'inspection',
});

/*
 * The document is whichever one this dialog was opened from, and the kind
 * starts over every time. A person who extracted an inspection report last
 * Tuesday must not silently send a contract down the inspection prompt — the
 * same argument S21's visibility picker makes for resetting to Internal
 * (#72): the two mistakes do not cost the same.
 */
watch(
    () => [props.open, props.documentId] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.documentId = props.documentId;
        form.kind = 'contract';
    },
    { immediate: true },
);

const KINDS: {
    value: 'contract' | 'inspection';
    label: string;
    help: string;
}[] = [
    {
        value: 'contract',
        label: 'Contract',
        help: 'Pulls out every date and deadline, and the provisions that go with them. You review each one before it reaches this deal.',
    },
    {
        value: 'inspection',
        label: 'Inspection report',
        help: 'Proposes a task for each finding. You accept the ones you want; nothing is created until you do.',
    },
];

/**
 * The bar's width, as a percentage the browser can use.
 *
 * Clamped, because a cap that has been exceeded is a real state — a run can
 * finish over the line — and a bar wider than its track reads as a rendering
 * fault rather than as the fact it is.
 */
const barWidth = computed(
    () => `${Math.min(100, Math.max(0, props.spend.percent ?? 0))}%`,
);

/** Whether there is a ceiling to draw this month's spend against at all. */
const capped = computed(() => props.spend.cap !== null);

function submit(): void {
    form.post(`${props.dealUrl}/extractions`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Extract from this document</DialogTitle>
                <DialogDescription>
                    {{ documentName }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4">
                <!--
                    §7.4's PII-warning anatomy, and the same rule: this is a
                    compliance control, not a nicety, and it names what
                    happens rather than reassuring about it. PRD §4.10 and
                    §14.3 are both explicit that F10.5 narrows the exposure
                    and does not remove it, so neither does this paragraph.
                -->
                <section
                    class="flex items-start gap-2.5 rounded-lg border border-state-warning bg-state-warning-bg p-3.5"
                    data-slot="extraction-disclosure"
                >
                    <ShieldAlert
                        class="mt-0.5 size-4 shrink-0 text-state-warning"
                        aria-hidden="true"
                    />
                    <div class="flex flex-col gap-1">
                        <p class="text-13 font-semibold text-state-warning">
                            This document's words leave your account.
                        </p>
                        <p class="text-xs text-secondary-foreground">
                            The text is taken out of the document, account and
                            card numbers and other financial identifiers are
                            masked, and what is left is sent to an outside model
                            to read. Masking narrows what that company sees. It
                            does not remove it, and names, addresses and figures
                            in the body of the document go with it.
                        </p>
                        <p class="text-xs text-secondary-foreground">
                            Nothing the model proposes reaches this deal until a
                            person confirms it, one item at a time.
                        </p>
                    </div>
                </section>

                <!-- Where this team stands against its own cap. -->
                <section
                    class="flex flex-col gap-2 rounded-lg border p-3.5"
                    data-slot="extraction-spend"
                >
                    <div class="flex items-center gap-2">
                        <CircleDollarSign
                            class="size-4 shrink-0"
                            :class="
                                spend.warn
                                    ? 'text-state-warning'
                                    : 'text-muted-foreground'
                            "
                            aria-hidden="true"
                        />
                        <span class="text-13 font-semibold"
                            >Extraction this month</span
                        >
                        <div class="flex-1"></div>
                        <span class="tabular text-13 font-semibold">
                            <template v-if="capped">
                                {{ spend.used }} of {{ spend.cap }}
                            </template>
                            <template v-else>{{ spend.used }}</template>
                        </span>
                    </div>

                    <div
                        v-if="capped"
                        class="h-1.5 w-full overflow-hidden rounded bg-muted"
                        role="progressbar"
                        :aria-valuenow="Math.round(spend.percent ?? 0)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="`Extraction spend: ${spend.used} of ${spend.cap}`"
                    >
                        <div
                            class="h-full rounded"
                            :class="
                                spend.warn ? 'bg-state-warning' : 'bg-primary'
                            "
                            :style="{ width: barWidth }"
                        ></div>
                    </div>

                    <p
                        class="text-[11px]"
                        :class="
                            spend.warn
                                ? 'text-state-warning'
                                : 'text-muted-foreground'
                        "
                    >
                        <template v-if="spend.warn">
                            Close to the cap. When it is reached, extraction
                            stops until {{ spend.resetsAt }} — dates can still
                            be added by hand.
                        </template>
                        <template v-else-if="capped">
                            The allowance starts again on {{ spend.resetsAt }}.
                        </template>
                        <template v-else>
                            <!--
                                No ceiling, so there is nothing to start again —
                                the figure above is what this month has cost and
                                the month is still the unit it is counted in.
                            -->
                            No monthly ceiling is set. This month's total resets
                            on {{ spend.resetsAt }}.
                        </template>
                    </p>
                </section>

                <!-- What kind of document this is. -->
                <fieldset class="flex flex-col gap-2">
                    <Label as="legend">What is this document?</Label>
                    <label
                        v-for="kind in KINDS"
                        :key="kind.value"
                        class="flex items-start gap-2.5 rounded-md border p-3"
                        :class="
                            form.kind === kind.value
                                ? 'border-[1.5px] border-primary'
                                : ''
                        "
                    >
                        <input
                            v-model="form.kind"
                            type="radio"
                            :value="kind.value"
                            class="mt-0.5 accent-primary"
                            :disabled="!available"
                        />
                        <span class="flex flex-col gap-0.5">
                            <span class="text-13 font-semibold">{{
                                kind.label
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                kind.help
                            }}</span>
                        </span>
                    </label>
                </fieldset>

                <!--
                    §9.7: what happened, then what to do. The reason is the
                    server's, because "no provider is configured" and "this
                    team is over its cap" are different problems with
                    different people to talk to.
                -->
                <p
                    v-if="!available"
                    class="text-[11px] text-state-danger"
                    data-slot="extraction-unavailable"
                >
                    {{
                        unavailableReason ??
                        'Extraction is not switched on for this account.'
                    }}
                </p>

                <p
                    v-if="form.errors.kind || form.errors.documentId"
                    class="text-[11px] text-state-danger"
                >
                    {{ form.errors.kind ?? form.errors.documentId }}
                </p>
            </div>

            <DialogFooter class="gap-2">
                <AppButton
                    variant="secondary"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </AppButton>
                <AppButton
                    :disabled="!available || form.processing"
                    @click="submit"
                >
                    {{ form.processing ? 'Starting…' : 'Extract' }}
                </AppButton>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
