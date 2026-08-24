<script setup lang="ts">
/**
 * S16 — the deal timeline (PRD §4.4 F4.6–F4.8 · Design System §7.4 · #76).
 *
 * Screen Inventory calls the stage rail *"the one interaction with no obvious
 * precedent to copy"*: every other screen in the inventory has a reference in
 * Design references, and this one had to be invented. §7.4 is what came out of
 * that, specified to the pixel, and `StageRail`/`StageRow` build it.
 *
 * This page does three things and no more: it stacks one rail per workflow,
 * says something honest when there are none, and routes Advance into the dialog
 * S23 already owns.
 *
 * **It never mounts its own advance dialog.** `DealLayout` mounts one for all
 * eight deal tabs and `useAdvanceDialog` holds the target, so a second here
 * would be a second dialog over the same act — and `AdvanceWorkflow` is the one
 * thing this codebase keeps single.
 *
 * ## Ticking a box here is not advancing here
 *
 * The rail's task rows became live when S17 (#71) gave completion an endpoint,
 * and that does not make this a write screen for the *workflow*. Completing a
 * task posts to the task; whether the stage may now move is still asked by
 * `AdvanceWorkflow`, from the one dialog, when somebody presses Advance. What
 * the reader sees on the way back is the requirements pane opposite the
 * checklist recounting itself — which is the whole reason the two panes sit
 * side by side in §7.4.
 */
import { Head, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppButton from '@/components/app/AppButton.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import StageRail from '@/components/app/StageRail.vue';
import type { TimelineWorkflow } from '@/components/app/StageRail.vue';
import { useAdvanceDialog } from '@/composables/useAdvanceDialog';

const props = defineProps<{
    dealUrl: string;
    workflows: TimelineWorkflow[];
}>();

const { openAdvance } = useAdvanceDialog();

function advance(workflowId: string, stageId: string): void {
    openAdvance({
        dealId: props.dealUrl.split('/').pop() as string,
        workflowId,
        stageId,
    });
}

/**
 * The same two endpoints S17 posts to, from the rail's own checkbox.
 *
 * `preserveScroll`, because the stage being worked is somewhere down a rail of
 * twenty and the rail has just been scrolled to it.
 */
function setCompleted(taskId: string, completed: boolean): void {
    const url = `${props.dealUrl}/tasks/${taskId}/completion`;

    if (completed) {
        router.post(url, {}, { preserveScroll: true });

        return;
    }

    router.delete(url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Timeline" />

    <div class="flex flex-col gap-8 p-4 md:p-6">
        <!--
            Rails are stacked, not tabbed or merged. Two concurrent workflows
            are two sequences with no shared order — see `StageRail`'s docblock
            for why inventing one is the trap #76 warns about.
        -->
        <StageRail
            v-for="workflow in workflows"
            :key="workflow.id"
            :workflow="workflow"
            @advance="(stageId) => advance(workflow.id, stageId)"
            @complete="setCompleted"
        />

        <!--
            No workflow yet is an ordinary state, not an error: a deal created
            five minutes ago has none, and S28's Attach workflow is the thing
            that fixes it. The action goes to the overview, which is where
            attaching lives.
        -->
        <EmptyState
            v-if="workflows.length === 0"
            variant="empty"
            title="No workflow on this deal yet"
            description="A workflow is what gives a deal its stages. Attach one and the timeline fills in."
        >
            <template #action>
                <AppButton :href="dealUrl">
                    <Plus class="size-4" aria-hidden="true" />
                    Go to the overview
                </AppButton>
            </template>
        </EmptyState>
    </div>
</template>
