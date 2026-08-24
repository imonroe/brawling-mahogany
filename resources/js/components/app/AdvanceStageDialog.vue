<script setup lang="ts">
/**
 * S23 — the advance stage modal (PRD §4.4 F4.8, §5.4 · issue #77).
 *
 * ## The standard
 *
 * Screen Inventory, on why this is one of the fifteen hard screens: *"Must
 * explain refusal clearly enough to act on."* #77 puts it harder — this is
 * where the product's central promise, *"make it impossible to silently skip a
 * required step"*, either reads as helpful or reads as an obstruction, and the
 * difference is entirely in how well the refusal explains itself.
 *
 * So no gate is ever a count. Every one carries the sentence its own evaluator
 * wrote, and where the evaluator knows one, a link to the thing that clears it
 * (PRD §5.4 step 3).
 *
 * ## Several unmet is a different problem from one unmet
 *
 * #77 names it: *"a list of five blockers needs prioritising and grouping, not
 * five identical rows."* So with more than one blocker the list splits by
 * **what you would do next**, which is the only division that helps: the ones
 * somebody can go and clear, and the ones that cannot clear on their own and
 * need an override. Three of the seven gate types are in that second group for
 * the whole of Slice 2 — document present, action completed and date reached
 * each return `notYetWired()` — so it is the common case rather than an edge.
 *
 * With exactly one blocker the headings are dropped: a group heading over a
 * list of one is furniture.
 *
 * ## "What happens when you advance"
 *
 * Design System §7.4 specifies the block and then refuses to let it be
 * optional: *"Never ship the advance action without this block. An automation
 * that emails the wrong client cannot be recalled, and this is the last place
 * a human can catch it."* Its four entries come from the server
 * (`App\Support\Workflow\AdvancePreview`) in §7.4's fixed order, so what the
 * reader is promised is testable rather than being copy in a template.
 *
 * ## Loaded when it opens, never from a page prop
 *
 * §8.4 puts Advance in the deal header, so this can be opened from any of the
 * eight tabs — and its whole value is that the refusal describes *this
 * minute*. A gate a colleague cleared two minutes ago must not still be listed
 * as what is stopping the deal.
 *
 * ## Where the refusal after the click is shown
 *
 * Not here. `DealLayout` renders what the last attempt said, because a person
 * who closes this dialog still needs to see it. What this does on a refusal is
 * reload — and the reloaded checklist *is* the refusal, in the better form:
 * the gates that stopped it, with their sentences and their links, rather than
 * a bulleted repetition of them.
 */
import { router, usePage } from '@inertiajs/vue3';
import { CircleCheck, TriangleAlert, Zap } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { AdvanceTarget } from '@/composables/useAdvanceDialog';
import { usePermissions } from '@/composables/usePermissions';
import { formatCount } from '@/lib/formatters';
import { gateResolutionLink, isOverridable } from '@/lib/gates';
import type { GateSummary } from '@/lib/gates';
import { cn } from '@/lib/utils';
import AppButton from './AppButton.vue';
import GateRow from './GateRow.vue';
import OverrideGateDialog from './OverrideGateDialog.vue';

type Consequence = { kind: string; label: string; detail: string | null };

type Preview = {
    workflowId: string;
    workflowName: string;
    stage: {
        id: string;
        name: string;
        state: string;
        position: number | null;
        total: number;
    } | null;
    nextStage: { id: string; name: string } | null;
    isLastStage: boolean;
    gates: GateSummary[];
    counts: {
        total: number;
        blocking: number;
        advisory: number;
        overridden: number;
        cleared: number;
    };
    canAdvance: boolean;
    consequences: Consequence[];
    refusal: string | null;
};

const props = defineProps<{ target: AdvanceTarget | null }>();

const emit = defineEmits<{ close: [] }>();

const page = usePage();
const { can } = usePermissions();

const preview = ref<Preview | null>(null);
const loading = ref(false);
const failed = ref(false);
const submitting = ref(false);
const overriding = ref<GateSummary | null>(null);

const open = computed(() => props.target !== null);

const dealUrl = computed(() =>
    props.target ? `/deals/${props.target.dealId}` : '',
);

const blockers = computed(() =>
    (preview.value?.gates ?? []).filter((gate) => gate.blocksAdvance),
);

/**
 * The two things a reader can do about a blocker, in the order to try them.
 *
 * A gate whose evaluator named a resolution is one somebody can go and clear;
 * one that named none — every `notYetWired()` gate, and an approval waiting on
 * a person — either waits or gets overridden. The fixable ones go first, which
 * is #77's *"prioritising"*: the cheapest action at the top.
 */
const clearable = computed(() =>
    blockers.value.filter(
        (gate) => gateResolutionLink(gate, dealUrl.value) !== null,
    ),
);

const notClearable = computed(() =>
    blockers.value.filter(
        (gate) => gateResolutionLink(gate, dealUrl.value) === null,
    ),
);

/** Everything not in the way: advisories, overridden gates, and met ones. */
const notBlocking = computed(() =>
    (preview.value?.gates ?? []).filter((gate) => !gate.blocksAdvance),
);

const canAdvance = computed(() => preview.value?.canAdvance === true);

/*
 * §8.9: "A consequential dialog leads with a `size-[34px] rounded-full` tinted
 * icon circle." Which tint is the answer to the question the dialog exists to
 * ask, so it is derived rather than fixed: success when nothing is in the way,
 * warning when something is.
 */
const headline = computed(() => {
    if (preview.value?.refusal) {
        return {
            icon: TriangleAlert,
            tint: 'bg-state-warning-bg text-state-warning',
            title: 'This workflow will not move',
            description: preview.value.refusal,
        };
    }

    if (!canAdvance.value) {
        const count = blockers.value.length;

        return {
            icon: TriangleAlert,
            tint: 'bg-state-warning-bg text-state-warning',
            title:
                count === 1
                    ? 'One requirement is not met'
                    : `${count} requirements are not met`,
            description:
                count === 1
                    ? 'Clear it, or override it with a reason, and this stage can advance.'
                    : 'Each one below says what it is waiting on, and where to go.',
        };
    }

    return {
        icon: CircleCheck,
        tint: 'bg-state-success-bg text-state-success',
        title: preview.value?.isLastStage
            ? `Finish ${preview.value.workflowName}`
            : `Advance to ${preview.value?.nextStage?.name ?? 'the next stage'}`,
        description: preview.value?.isLastStage
            ? 'This is the last stage, so advancing completes the workflow. A completed workflow does not reopen.'
            : 'Nothing is standing in the way. Here is what happens when you do.',
    };
});

async function load(): Promise<void> {
    const target = props.target;

    if (!target) {
        return;
    }

    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(
            `/deals/${target.dealId}/workflows/${target.workflowId}/advance`,
            { headers: { Accept: 'application/json' } },
        );

        // A session that expired answers with HTML, which `json()` rejects on.
        const body = response.ok ? await response.json() : null;

        // The dialog was closed, or reopened on another workflow, while this
        // request was in flight.
        if (props.target?.workflowId !== target.workflowId) {
            return;
        }

        preview.value = body;
        failed.value = body === null;
    } catch {
        if (props.target?.workflowId === target.workflowId) {
            preview.value = null;
            failed.value = true;
        }
    } finally {
        if (props.target?.workflowId === target.workflowId) {
            loading.value = false;
        }
    }
}

watch(
    () => props.target,
    (target) => {
        preview.value = null;
        overriding.value = null;

        if (target) {
            void load();
        }
    },
    { immediate: true },
);

function advance(): void {
    const target = props.target;
    const stage = preview.value?.stage;

    if (!target || !stage || submitting.value) {
        return;
    }

    submitting.value = true;

    router.post(
        `/deals/${target.dealId}/workflows/${target.workflowId}/advance`,
        // The stage this dialog was rendered from, not "whatever is current
        // now". `AdvanceWorkflow` refuses when somebody else has moved on.
        { expected_stage_id: stage.id },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                submitting.value = false;
            },
            onSuccess: () => {
                /*
                 * The controller redirects back whether it advanced or not, so
                 * Inertia calls this either way. The flash is the only thing
                 * that tells them apart — and it is read to decide whether to
                 * stay open, never to render, because `DealLayout` renders it.
                 */
                const refused = (
                    page.props.flash as { advance?: unknown } | undefined
                )?.advance;

                if (refused) {
                    void load();

                    return;
                }

                emit('close');
            },
        },
    );
}

function onOverridden(): void {
    overriding.value = null;
    void load();
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(value) => (value ? undefined : emit('close'))"
    >
        <!--
            §8.9: 660px, because this carries a checklist. `p-0` and `gap-0`
            because the bands below are full-bleed and own their padding — and
            "dialogs must not scroll their own header or footer away", so only
            the middle is a scroll container.
        -->
        <DialogContent
            class="flex max-h-[85svh] flex-col gap-0 overflow-hidden p-0 sm:max-w-[660px]"
        >
            <div class="flex items-start gap-3 border-b px-6 py-5">
                <span
                    :class="
                        cn(
                            'flex size-[34px] shrink-0 items-center justify-center rounded-full',
                            headline.tint,
                        )
                    "
                >
                    <component
                        :is="headline.icon"
                        class="size-[18px]"
                        aria-hidden="true"
                    />
                </span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <DialogTitle class="text-lg font-semibold">{{
                        headline.title
                    }}</DialogTitle>
                    <DialogDescription class="text-13 text-muted-foreground">{{
                        headline.description
                    }}</DialogDescription>
                </div>
            </div>

            <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <p
                    v-if="loading && !preview"
                    class="px-6 py-[18px] text-13 text-muted-foreground"
                >
                    Checking what is in the way…
                </p>

                <p
                    v-else-if="failed"
                    class="px-6 py-[18px] text-13 text-state-danger"
                >
                    Couldn’t read this workflow. Refresh the page and try again
                    — nothing has moved.
                </p>

                <template v-else-if="preview && preview.stage">
                    <!--
                        The checklist. §7.4's requirement row, in its bordered
                        density, with a count in the heading a reader can check
                        against the rows below it.
                    -->
                    <section
                        v-if="preview.gates.length > 0"
                        class="flex flex-col gap-3 border-b px-6 py-[18px]"
                    >
                        <h3
                            class="text-xs font-semibold text-muted-foreground uppercase"
                        >
                            Requirements to advance ·
                            <span
                                :class="
                                    preview.counts.blocking > 0
                                        ? 'text-state-warning'
                                        : ''
                                "
                                ><!--
                                    "cleared", not "met". `counts.cleared` is
                                    `met + overridden`, and IA §8 is explicit
                                    that Overridden is its own state and not a
                                    kind of Met — so one waived gate read
                                    "1 of 1 met" directly above a row badged
                                    Overridden, which is the same contradiction
                                    the badge ordering on S15 was fixed for.
                                -->{{ preview.counts.cleared }} of
                                {{ preview.counts.total }} cleared</span
                            >
                        </h3>

                        <!--
                            #77: five blockers need grouping, not five
                            identical rows. The headings appear only once there
                            is more than one — over a list of one they are
                            furniture.
                        -->
                        <template v-if="clearable.length > 0">
                            <p
                                v-if="blockers.length > 1"
                                class="text-xs font-medium text-secondary-foreground"
                                data-slot="clearable-heading"
                            >
                                {{
                                    formatCount(clearable.length, 'requirement')
                                }}
                                you can clear now
                            </p>
                            <GateRow
                                v-for="gate in clearable"
                                :key="gate.id"
                                :gate="gate"
                                boxed
                            >
                                <template #action>
                                    <AppButton
                                        variant="secondary"
                                        size="compact"
                                        :href="
                                            gateResolutionLink(gate, dealUrl)!
                                        "
                                        >Go and clear it</AppButton
                                    >
                                </template>
                            </GateRow>
                        </template>

                        <template v-if="notClearable.length > 0">
                            <p
                                v-if="blockers.length > 1"
                                class="text-xs font-medium text-secondary-foreground"
                                data-slot="not-clearable-heading"
                            >
                                {{
                                    formatCount(
                                        notClearable.length,
                                        'requirement',
                                    )
                                }}
                                that cannot clear on its own
                            </p>
                            <GateRow
                                v-for="gate in notClearable"
                                :key="gate.id"
                                :gate="gate"
                                boxed
                            >
                                <template #action>
                                    <!--
                                        IA §7: the verb is Override, never
                                        Bypass, Force, Skip or Ignore. Hidden
                                        rather than disabled when the person
                                        may not (§7.3) — a disabled control
                                        still advertises a capability.
                                    -->
                                    <AppButton
                                        v-if="
                                            isOverridable(gate) &&
                                            can('workflow.override')
                                        "
                                        variant="secondary"
                                        size="compact"
                                        @click="overriding = gate"
                                        >Override</AppButton
                                    >
                                </template>
                            </GateRow>
                        </template>

                        <!--
                            Advisories, overrides and met gates. Quieter than a
                            blocker on purpose: an advisory that looks like a
                            wall teaches people to ignore both (#77).
                        -->
                        <GateRow
                            v-for="gate in notBlocking"
                            :key="gate.id"
                            :gate="gate"
                            boxed
                        />
                    </section>

                    <section
                        v-else
                        class="border-b px-6 py-[18px] text-13 text-muted-foreground"
                    >
                        This stage has no requirements on it. Advancing is a
                        decision rather than a check.
                    </section>

                    <!-- §7.4's "What happens when you advance" block. -->
                    <section class="flex flex-col gap-3 px-6 py-[18px]">
                        <h3
                            class="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground uppercase"
                        >
                            <Zap class="size-3.5" aria-hidden="true" />
                            What happens when you advance
                        </h3>
                        <ul class="flex flex-col gap-2.5">
                            <li
                                v-for="entry in preview.consequences"
                                :key="entry.kind"
                                class="flex flex-col gap-0.5"
                                :data-slot="`consequence-${entry.kind}`"
                            >
                                <span
                                    class="text-13 font-medium text-foreground"
                                    >{{ entry.label }}</span
                                >
                                <span
                                    v-if="entry.detail"
                                    class="text-xs text-muted-foreground"
                                    >{{ entry.detail }}</span
                                >
                            </li>
                        </ul>
                    </section>
                </template>
            </div>

            <!-- §8.9's footer: a muted note on the left, cancel then primary. -->
            <div class="flex items-center gap-2.5 border-t bg-muted px-6 py-4">
                <p
                    v-if="preview?.stage?.position"
                    class="min-w-0 flex-1 truncate text-xs text-muted-foreground"
                >
                    Stage {{ preview.stage.position }} of
                    {{ preview.stage.total }} · {{ preview.workflowName }}
                </p>
                <span v-else class="flex-1" />

                <AppButton variant="ghost" @click="emit('close')"
                    >Cancel</AppButton
                >
                <AppButton
                    v-if="!preview?.refusal"
                    :disabled="!canAdvance || submitting || loading"
                    @click="advance"
                    >{{
                        preview?.isLastStage
                            ? 'Advance and finish'
                            : 'Advance stage'
                    }}</AppButton
                >
            </div>
        </DialogContent>
    </Dialog>

    <OverrideGateDialog
        v-if="target && preview?.stage"
        :gate="overriding"
        :deal-id="target.dealId"
        :workflow-id="target.workflowId"
        :stage-name="preview.stage.name"
        @close="overriding = null"
        @overridden="onOverridden"
    />
</template>
