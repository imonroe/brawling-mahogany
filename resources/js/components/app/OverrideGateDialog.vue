<script setup lang="ts">
/**
 * S24 — override a gate with a reason (PRD §4.4 F4.9, §5.5 · IA §7 · #69).
 *
 * ## Override is not Skip, and the label is the whole argument
 *
 * IA §7, flagged as legally material: *"**Override** means the gate should
 * have been met and was not, and you are proceeding anyway. **Skip** means the
 * stage does not apply to this deal at all. Conflating them in a label
 * destroys the audit trail's meaning."* So the verb here is Override and never
 * Bypass, Force, Skip or Ignore, and nothing on this screen offers the other
 * one.
 *
 * ## The consequence is shown, not implied
 *
 * Design System §10: *"Consequential inputs carry their consequence beneath
 * them. The override reason field (S24) is followed by 'This is written to the
 * permanent audit log with your name and the time. It cannot be edited or
 * deleted,' and then by a preview of the follow-up task the override will
 * create."* Both are below, in that order, because the second is the one
 * people do not expect: #69 calls the follow-up *"the reason the feature is
 * safe — an override defers an obligation; it does not delete one."*
 *
 * ## Overriding does not advance
 *
 * It clears one gate. The advance dialog reopens onto the refreshed checklist
 * and Advance is a second, deliberate press. Overriding one of three blockers
 * must not move the deal past the other two, and PRD §5.5 reads as one motion
 * only because the two screens hand off cleanly.
 */
import { useForm } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { GateSummary } from '@/lib/gates';
import AppButton from './AppButton.vue';
import AppTextarea from './AppTextarea.vue';

const props = defineProps<{
    gate: GateSummary | null;
    dealId: string;
    workflowId: string;
    stageName: string;
}>();

const emit = defineEmits<{ close: []; overridden: [] }>();

const form = useForm({ gate_id: '', reason: '' });

const open = computed(() => props.gate !== null);

watch(
    () => props.gate,
    (gate) => {
        form.reset();
        form.clearErrors();
        form.gate_id = gate?.id ?? '';
    },
);

function submit(): void {
    if (!props.gate) {
        return;
    }

    form.post(`/deals/${props.dealId}/workflows/${props.workflowId}/override`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('overridden'),
    });
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(value) => (value ? undefined : emit('close'))"
    >
        <!-- §8.9: 600px for a focused decision, rather than 660 for a list. -->
        <DialogContent
            class="flex max-h-[85svh] flex-col gap-0 overflow-hidden p-0 sm:max-w-[600px]"
        >
            <div class="flex items-start gap-3 border-b px-6 py-5">
                <!--
                    §8.9's consequential icon circle. `shield-alert` in
                    `state-warning`, which is the marker §7.4 gives an override
                    on the stage rail and `lib/activity.ts` gives it on the
                    timeline — one fact, one glyph, wherever it appears.
                -->
                <span
                    class="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-state-warning-bg text-state-warning"
                >
                    <ShieldAlert class="size-[18px]" aria-hidden="true" />
                </span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <DialogTitle class="text-lg font-semibold"
                        >Override {{ gate?.label }}</DialogTitle
                    >
                    <DialogDescription class="text-13 text-muted-foreground">
                        This requirement on {{ stageName }} has not been met.
                        Overriding it lets the stage advance and records that
                        you decided to proceed without it.
                    </DialogDescription>
                </div>
            </div>

            <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <!--
                    §8.9's inline alert band: full-bleed, filled with the
                    relevant state background. The evaluator's own sentence,
                    because "no inspection report is attached" is what somebody
                    is overriding and a generic "unmet" is not.
                -->
                <p
                    v-if="gate"
                    class="border-b bg-state-warning-bg px-6 py-3.5 text-13 text-secondary-foreground"
                >
                    {{ gate.explanation }}
                </p>

                <section class="flex flex-col gap-3 border-b px-6 py-[18px]">
                    <label
                        for="override-reason"
                        class="flex items-center gap-2 text-sm font-medium text-foreground"
                    >
                        Why are you proceeding without it?
                        <!-- §10: mark required fields, not optional ones. -->
                        <span class="text-xs font-medium text-destructive"
                            >Required</span
                        >
                    </label>

                    <AppTextarea
                        id="override-reason"
                        v-model="form.reason"
                        :invalid="Boolean(form.errors.reason)"
                        placeholder="Appraisal received by email, uploading tomorrow."
                    />

                    <p
                        v-if="form.errors.reason"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.reason }}
                    </p>
                    <p
                        v-else-if="form.errors.gate_id"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.gate_id }}
                    </p>
                    <!--
                        §10's exact wording for this field. It is not softened
                        and not collapsed: an override is the one action in the
                        product whose whole value is that it is on the record.
                    -->
                    <p v-else class="text-xs text-muted-foreground">
                        This is written to the permanent audit log with your
                        name and the time. It cannot be edited or deleted.
                    </p>
                </section>

                <!-- §10: "and then by a preview of the follow-up task". -->
                <section class="flex flex-col gap-2 px-6 py-[18px]">
                    <h3
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        This also creates a task
                    </h3>
                    <div class="flex flex-col gap-0.5 rounded-md border p-3">
                        <span class="text-13 font-medium text-foreground"
                            >Follow up on the overridden gate:
                            {{ gate?.label }}</span
                        >
                        <span class="text-xs text-muted-foreground">
                            Assigned to you, on {{ stageName }}, due today. An
                            override defers this — it does not delete it.
                        </span>
                    </div>
                </section>
            </div>

            <div class="flex items-center gap-2.5 border-t bg-muted px-6 py-4">
                <span class="flex-1" />
                <AppButton variant="ghost" @click="emit('close')"
                    >Cancel</AppButton
                >
                <!--
                    `warning`, not `destructive`. Nothing is destroyed; a
                    decision is recorded, and §2.4 reserves red for loss.
                -->
                <AppButton
                    variant="warning"
                    :disabled="form.processing"
                    @click="submit"
                    >Override</AppButton
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
