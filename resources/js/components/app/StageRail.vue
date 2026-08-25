<script setup lang="ts">
/**
 * One workflow's stage rail (S16 · Design System §7.4 · #76).
 *
 * ## One rail per workflow, never a merged one
 *
 * PRD F4.7 lets pre-listing improvements and the sale itself run concurrently,
 * and #76 names that as the case that *"breaks naive designs"*. It breaks them
 * by inviting a single merged rail — and two workflows have independent stage
 * sequences with no shared order, so a merged rail has to invent one. Sorting
 * by date is the obvious invention and it is wrong: stage four of the sale can
 * be planned before stage two of the improvements without either being "next",
 * and a reader following one line down the page would read a sequence nobody
 * intends to work in.
 *
 * So the page stacks rails, each headed by its workflow. That is also what
 * `DealHeader::advance()` already assumes when it refuses to name a single
 * advance target while two workflows are running.
 *
 * ## Twenty stages without losing your place
 *
 * #76's definition of done asks that *"a 20-stage workflow does not require the
 * user to lose their place"*. Two things answer it, and neither is a scrollbar:
 *
 * - The active stage opens expanded, and everything else opens collapsed, so
 *   the one row that needs reading is the one row that is tall.
 * - The rail scrolls that row into view — on arrival, and again whenever an
 *   advance moves the workflow on — so neither landing on stage seventeen of
 *   twenty nor advancing into it begins with a thousand pixels of history.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import StageRow from './StageRow.vue';
import type { TimelineStage } from './StageRow.vue';
import StatusBadge from './StatusBadge.vue';

export type TimelineWorkflow = {
    id: string;
    name: string;
    state: string;
    stateLabel: string;
    isRunning: boolean;
    refusal: string | null;
    activeStageId: string | null;
    canAdvance: boolean;
    stages: TimelineStage[];
};

const props = defineProps<{ workflow: TimelineWorkflow }>();

const emit = defineEmits<{
    advance: [stageId: string];
    /** F4.12's two verbs, forwarded with the row they came from (#70). */
    skip: [stageId: string];
    reopen: [stageId: string];
    /** A task on one of this rail's stages was ticked, or unticked (#71). */
    complete: [taskId: string, completed: boolean];
}>();

/*
 * Which rows are open, by stage id.
 *
 * Seeded with the active stage and nothing else. A `Set` rather than a flag on
 * each stage, because the stages arrive as props and a component that wrote
 * into its own props would be the second place this screen's state lives.
 */
const opened = ref(
    new Set<string>(
        props.workflow.activeStageId === null
            ? []
            : [props.workflow.activeStageId],
    ),
);

/**
 * **The rail follows the workflow when it moves.**
 *
 * `AdvanceStageDialog` posts with `preserveState: true`, and Inertia only
 * re-keys the page component when state is *not* preserved — so advancing does
 * not remount this rail. Seeding `opened` in `setup()` and stopping there left
 * the **just-completed** stage expanded and the new active one shut, with no
 * scroll: exactly the "lose your place" failure #76 asks the screen to avoid,
 * arriving at the one moment the reader has most reason to look.
 *
 * Opening rather than replacing, because a row the reader opened themselves is
 * theirs. Advancing adds the new current stage to whatever they had open; it
 * does not tidy the screen up behind them.
 */
watch(
    () => props.workflow.activeStageId,
    (stageId, previous) => {
        if (stageId === null || stageId === previous) {
            return;
        }

        opened.value = new Set(opened.value).add(stageId);

        // After the row has actually rendered expanded, or the scroll measures
        // a 58px row and lands short of it.
        void nextTick(() => scrollToActive());
    },
);

function toggle(stageId: string): void {
    const next = new Set(opened.value);

    if (!next.delete(stageId)) {
        next.add(stageId);
    }

    opened.value = next;
}

/*
 * A function ref, not a string one.
 *
 * A string `ref` inside `v-for` collects into an *array* rather than an
 * element, and a conditional string ref collects a sparse one — so
 * `activeRow.value.scrollIntoView` would be reaching through whatever Vue
 * happened to put at index zero. Capturing the element directly says what it
 * means.
 */
const activeRow = ref<HTMLElement | null>(null);

function captureActive(element: unknown, stageId: string): void {
    if (stageId === props.workflow.activeStageId) {
        activeRow.value = (element as HTMLElement | null) ?? null;
    }
}

function scrollToActive(): void {
    /*
     * `block: 'center'` rather than the default `start`, so the active stage
     * arrives with the stages either side of it visible. The whole argument
     * for a rail over a list is that a stage is legible in its sequence, and
     * scrolling it to the top edge throws away the half of that sequence that
     * has already happened.
     *
     * `auto` rather than `smooth`: this fires on arrival, and a page that
     * animates itself out from under the reader on load is a page that has
     * moved by the time they look at it.
     *
     * Called through `?.()` because `scrollIntoView` is not universal — jsdom
     * does not implement it, so a bare call throws in every component test that
     * mounts a rail, and a screen that cannot be tested because of how it
     * scrolls has traded the wrong thing away.
     */
    activeRow.value?.scrollIntoView?.({ block: 'center', behavior: 'auto' });
}

onMounted(scrollToActive);

const total = computed(() => props.workflow.stages.length);
</script>

<template>
    <section class="flex flex-col gap-3" data-slot="stage-rail">
        <header class="flex flex-wrap items-center gap-2.5">
            <h2 class="text-[15px] font-semibold" data-slot="workflow-name">
                {{ workflow.name }}
            </h2>
            <StatusBadge domain="workflow" :state="workflow.state" />
            <span class="text-xs text-muted-foreground"
                >{{ total }} {{ total === 1 ? 'stage' : 'stages' }}</span
            >
        </header>

        <!--
            The refusal, said once per rail rather than once per row. A workflow
            that is on hold or finished has no Advance anywhere on it, and
            twenty rows each explaining that separately is twenty copies of one
            sentence.
        -->
        <p
            v-if="workflow.refusal"
            class="text-xs text-muted-foreground"
            data-slot="workflow-refusal"
        >
            {{ workflow.refusal }}
        </p>

        <div class="flex flex-col">
            <div
                v-for="(stage, index) in workflow.stages"
                :key="stage.id"
                :ref="(element) => captureActive(element, stage.id)"
            >
                <StageRow
                    :stage="stage"
                    :total="total"
                    :is-last="index === workflow.stages.length - 1"
                    :expanded="opened.has(stage.id)"
                    :can-advance="workflow.canAdvance"
                    :advance-refusal="workflow.refusal"
                    @toggle="toggle(stage.id)"
                    @advance="emit('advance', stage.id)"
                    @skip="emit('skip', stage.id)"
                    @reopen="emit('reopen', stage.id)"
                    @complete="
                        (taskId, completed) =>
                            emit('complete', taskId, completed)
                    "
                />
            </div>
        </div>
    </section>
</template>
