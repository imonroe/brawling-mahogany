<script setup lang="ts">
/**
 * Mark a stage not applicable to this deal (PRD §4.4 F4.12 · IA §7 · #70).
 *
 * ## Skip is not Override, and the copy is the whole argument
 *
 * IA §7, flagged as legally material: *"**Override** means the gate should
 * have been met and was not, and you are proceeding anyway. **Skip** means the
 * stage does not apply to this deal at all."* So nothing here says Bypass,
 * Force, Ignore or Dismiss, nothing here mentions gates, and the sentence
 * beneath the field asks a different question from the override's — not *why
 * are you proceeding without it*, but *why does this not apply*.
 *
 * ## No follow-up task, deliberately
 *
 * `OverrideGateDialog` previews one, because #69's whole safety argument is
 * that *"an override defers an obligation; it does not delete one."* A skip
 * has no obligation to defer: the appraisal contingency on a cash purchase is
 * not late, it is absent. Creating a task here would put process failures and
 * deals that simply differ into the same list, which is the confusion the two
 * verbs exist to prevent.
 */
import { useForm } from '@inertiajs/vue3';
import { CircleSlash } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from './AppButton.vue';
import AppTextarea from './AppTextarea.vue';

const props = defineProps<{
    /** The stage being skipped, or null when the dialog is closed. */
    stage: { id: string; name: string } | null;
    dealId: string;
    workflowId: string;
}>();

const emit = defineEmits<{ close: []; skipped: []; refused: [] }>();

const form = useForm({ reason: '' });

/** Why the last attempt was refused — not a validation error, an answer. */
const refusal = ref<string | null>(null);

const open = computed(() => props.stage !== null);

watch(
    () => props.stage,
    () => {
        form.reset();
        form.clearErrors();
        refusal.value = null;
    },
);

function submit(): void {
    if (!props.stage) {
        return;
    }

    form.post(
        `/deals/${props.dealId}/workflows/${props.workflowId}/stages/${props.stage.id}/skip`,
        {
            preserveScroll: true,
            preserveState: true,
            /*
             * A refused skip is a **successful request**, the same way a
             * refused override is: a workflow put on hold, or a colleague who
             * advanced past the stage while this was open. The flash is what
             * tells them apart — a refusal sets `advance`, a success `toast`.
             */
            onSuccess: (page) => {
                const advance = (
                    page.props.flash as
                        | {
                              advance?: {
                                  refused?: boolean;
                                  reasons?: string[];
                              };
                          }
                        | undefined
                )?.advance;

                if (advance?.refused === true) {
                    refusal.value =
                        advance.reasons?.[0] ??
                        'That stage could not be skipped.';

                    emit('refused');

                    return;
                }

                emit('skipped');
            },
        },
    );
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(value) => (value ? undefined : emit('close'))"
    >
        <!-- §8.9: 600px for a focused decision. -->
        <DialogContent
            class="flex max-h-[85svh] flex-col gap-0 overflow-hidden p-0 sm:max-w-[600px]"
        >
            <div class="flex items-start gap-3 border-b px-6 py-5">
                <!--
                    Neutral, not warning. §7.4 badges a skipped stage neutral
                    and IA §8 hides it from the client entirely: nothing went
                    wrong here, and dressing it in amber would say it did.
                -->
                <span
                    class="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <CircleSlash class="size-[18px]" aria-hidden="true" />
                </span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <DialogTitle class="text-lg font-semibold"
                        >Skip {{ stage?.name }}</DialogTitle
                    >
                    <DialogDescription class="text-13 text-muted-foreground">
                        This marks the stage not applicable to this deal. It is
                        not the same as advancing past it: nothing here is
                        recorded as having been done.
                    </DialogDescription>
                </div>
            </div>

            <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <section class="flex flex-col gap-3 px-6 py-[18px]">
                    <label
                        for="skip-reason"
                        class="flex items-center gap-2 text-sm font-medium text-foreground"
                    >
                        Why does this stage not apply?
                        <!-- §10: mark required fields, not optional ones. -->
                        <span class="text-xs font-medium text-destructive"
                            >Required</span
                        >
                    </label>

                    <AppTextarea
                        id="skip-reason"
                        v-model="form.reason"
                        :invalid="Boolean(form.errors.reason)"
                        placeholder="Cash purchase, so there is no financing contingency."
                    />

                    <p
                        v-if="form.errors.reason"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.reason }}
                    </p>
                    <p v-else-if="refusal" class="text-xs text-destructive">
                        {{ refusal }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        This is written to the permanent audit log with your
                        name and the time. Six weeks from now it is the only
                        thing distinguishing a deal that differed from a stage
                        somebody clicked past.
                    </p>
                </section>
            </div>

            <div class="flex items-center gap-2.5 border-t bg-muted px-6 py-4">
                <span class="flex-1" />
                <AppButton variant="ghost" @click="emit('close')"
                    >Cancel</AppButton
                >
                <!--
                    Neither `warning` nor `destructive`. Nothing is destroyed
                    and nothing is being forced; a stage is being described.
                -->
                <AppButton :disabled="form.processing" @click="submit"
                    >Skip stage</AppButton
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
