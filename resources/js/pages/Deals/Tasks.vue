<script setup lang="ts">
/**
 * S17 — a deal's tasks (PRD §4.4 F4.10 · §7.10 · issue #71).
 *
 * ## The screen the product is sold on
 *
 * Two practitioners named customizable task lists independently as the thing
 * their tools do not have. Everything else in Slice 2 exists so this list can
 * be right: the template layer is where it comes from, the stage rail is where
 * it sits, and the `required_tasks_complete` gate is the list deciding whether
 * a deal may move.
 *
 * ## Grouped by stage, in workflow order
 *
 * Not sorted by urgency across the deal. A checklist is a procedure and a
 * procedure read out of order is not the procedure — *"what should I do
 * next"* across every deal is S11, which is a different screen for a different
 * question. Urgency orders the rows *inside* a group, where the sequence is
 * already established.
 *
 * ## The filter does not go to the server
 *
 * S13 sends every filter to the server because a team has several hundred
 * closed deals and filtering them in the browser would mean shipping them
 * there. A deal's tasks are tens, they are all on the page already, and the
 * three views are three predicates over an array — so this one is local, and
 * the whole family of stale-props races S13 had to solve does not exist here.
 */
import { Head, router } from '@inertiajs/vue3';
import { ListChecks, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import Heading from '@/components/app/Heading.vue';
import IconButton from '@/components/app/IconButton.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TaskFormDialog from '@/components/app/TaskFormDialog.vue';
import type {
    StageOptionGroup,
    TaskFormValues,
} from '@/components/app/TaskFormDialog.vue';
import TaskItem from '@/components/app/TaskItem.vue';
import { usePermissions } from '@/composables/usePermissions';

type TaskRow = {
    id: string;
    title: string;
    description: string | null;
    stageId: string | null;
    state: string;
    isRequired: boolean;
    dueDate: string | null;
    completedAt: string | null;
    completedByName: string | null;
    assigneeId: string | null;
    assigneeName: string | null;
    source: string;
    sourceLabel: string;
};

type Group = {
    key: string;
    stageId: string | null;
    stageName: string | null;
    workflowName: string | null;
    isCurrent: boolean;
    tasks: TaskRow[];
};

const props = defineProps<{
    dealHeader: DealHeaderProps;
    dealUrl: string;
    groups: Group[];
    counts: {
        open: number;
        completed: number;
        all: number;
        overdue: number;
        unassigned: number;
    };
    assignees: { id: string; name: string }[];
    stageOptions: StageOptionGroup[];
}>();

const { can } = usePermissions();

/** PRD §4.2 F2.2's Read Only role reads this screen and changes nothing. */
const canManage = computed(() => can('deals.manage'));

/**
 * Who may delete this particular task.
 *
 * PRD F4.9's fourth artefact is the follow-up an override leaves behind, and
 * it is the only one of the four that lives on a screen rather than in the
 * audit log — so dropping it asks for the permission that created it.
 * `TaskPolicy::delete()` is what decides; this hides the control rather than
 * offering one that 403s, per §7.3.
 */
function canDelete(task: TaskRow): boolean {
    return (
        canManage.value &&
        (task.source !== 'override' || can('workflow.override'))
    );
}

/*
 * Open by default. A deal that has run for a month has more completed tasks
 * than open ones, and the screen is opened to answer "what is left" — a first
 * paint that leads with forty ticked boxes answers a question nobody asked.
 */
const view = ref<'open' | 'completed' | 'all'>('open');

const segments = computed(() => [
    { value: 'open', label: 'Open', count: props.counts.open },
    { value: 'completed', label: 'Completed', count: props.counts.completed },
    { value: 'all', label: 'All', count: props.counts.all },
]);

function visible(tasks: TaskRow[]): TaskRow[] {
    if (view.value === 'all') {
        return tasks;
    }

    const wantComplete = view.value === 'completed';

    return tasks.filter(
        (task) => (task.state === 'completed') === wantComplete,
    );
}

/**
 * The groups with something to show under the current filter.
 *
 * A stage header over an empty list is a row of furniture: the reader has to
 * read it to find out it holds nothing. On **Open**, a stage whose work is
 * finished simply leaves the page — which is the honest rendering of a
 * checklist somebody has worked through.
 */
const shownGroups = computed(() =>
    props.groups
        .map((group) => ({ ...group, tasks: visible(group.tasks) }))
        .filter((group) => group.tasks.length > 0),
);

/**
 * §7.3's meta line, inside a deal.
 *
 * The spec gives it the completion attribution — *"Completed by Heather"* —
 * and the two facts a reader needs before that are whether the task is
 * required and whether anybody owns it. Issue #71: *"Unassigned is a visible
 * state, not a silent default."*
 *
 * A task the machine or an override created says so, because PRD §4.10 is firm
 * that nothing a model proposes may read as something a person typed. A task
 * somebody typed says nothing — `manual` is the ordinary case, and labelling
 * the ordinary case is noise on every row.
 */
function metaFor(task: TaskRow): string | null {
    const parts: string[] = [];

    if (task.isRequired) {
        parts.push('Required');
    }

    if (task.state === 'completed') {
        if (task.completedByName) {
            parts.push(`Completed by ${task.completedByName}`);
        }
    } else if (!task.assigneeId) {
        parts.push('Unassigned');
    }

    if (task.source !== 'manual' && task.source !== 'template') {
        parts.push(task.sourceLabel);
    }

    return parts.length > 0 ? parts.join(' · ') : null;
}

/**
 * Completing, and unticking.
 *
 * Two routes rather than one with a flag, matching the server: completing
 * writes an activity event and is what the stage's requirement counts, and
 * reopening says the team has decided the work is not done after all.
 *
 * `preserveScroll`, because the row that was just ticked is somewhere down a
 * long checklist and jumping to the top of it loses the reader's place. And
 * `preserveState`, because the view above is a local ref: without it, ticking
 * a box while reading **Completed** or **All** remounts the page and drops the
 * reader back to **Open** — which is the filter working against the person
 * using it.
 */
const VISIT = { preserveScroll: true, preserveState: true } as const;

function setCompleted(task: TaskRow, completed: boolean): void {
    const url = `${props.dealUrl}/tasks/${task.id}/completion`;

    if (completed) {
        router.post(url, {}, VISIT);

        return;
    }

    router.delete(url, VISIT);
}

const editing = ref<TaskFormValues | null>(null);
const editorStageId = ref<string | null>(null);
const editorOpen = ref(false);

/**
 * The row behind the open dialog, so the dialog's Delete asks the same
 * question the row's does — `TaskPolicy::delete()` refuses an override's
 * follow-up to somebody without `workflow.override`, and a control that
 * appears in one place and not the other is the shape of an eventual 403.
 */
const editingTask = computed(() =>
    editing.value === null
        ? null
        : (props.groups
              .flatMap((group) => group.tasks)
              .find((row) => row.id === editing.value?.id) ?? null),
);

function addTask(stageId: string | null): void {
    editing.value = null;
    editorStageId.value = stageId;
    editorOpen.value = true;
}

function editTask(task: TaskRow): void {
    editing.value = {
        id: task.id,
        title: task.title,
        description: task.description,
        stageId: task.stageId,
        assigneeId: task.assigneeId,
        assigneeName: task.assigneeName,
        dueDate: task.dueDate,
        isRequired: task.isRequired,
    };
    editorStageId.value = task.stageId;
    editorOpen.value = true;
}

/*
 * IA §7: **Delete** destroys, **Remove** detaches and the record survives.
 * This one destroys — a task belongs to one deal and there is nothing for it
 * to survive as — so the copy says delete, and says the recovery window PRD §9
 * gives rather than implying there is none.
 */
/** Whether the reader went through with it, so a caller can react. */
function deleteTask(task: TaskRow): boolean {
    if (
        !window.confirm(
            `Delete “${task.title}”? It leaves this deal’s checklist. Deleted work is recoverable for 30 days.`,
        )
    ) {
        return false;
    }

    // `preserveState` for the same reason every other write on this screen
    // carries it: the view above is a local ref, and a remount drops the
    // reader back to Open.
    router.delete(`${props.dealUrl}/tasks/${task.id}`, VISIT);

    return true;
}

/**
 * The dialog's own Delete, which the row hides below `sm`.
 *
 * **Confirm first, close second.** The first version of this closed the dialog
 * and then asked — so cancelling deleted nothing *and* threw away whatever the
 * reader had typed, which is the worst of both answers. Found by review on
 * #71, in the fix from the round before.
 */
function deleteFromDialog(id: string): void {
    const task = props.groups
        .flatMap((group) => group.tasks)
        .find((row) => row.id === id);

    if (task && deleteTask(task)) {
        editorOpen.value = false;
    }
}

/**
 * The deal header's **Add Task** button (Design System §8.4), which lands here.
 *
 * §8.4 puts the button in the chrome every deal tab wears, and the form it
 * opens needs this deal's stages and this team's assignees — a payload the
 * other seven tabs have no other use for. So the header links here with
 * `?new`, and this is where that is picked up: one payload, one form, and the
 * button works from every tab.
 */
onMounted(() => {
    if (
        new URLSearchParams(window.location.search).has('new') &&
        canManage.value
    ) {
        addTask(null);
    }
});
</script>

<template>
    <Head :title="`Tasks — ${dealHeader.name}`" />

    <!-- §9.2: the DealHeader above is full-bleed; the tab body owns its p-6. -->
    <div class="flex flex-col gap-4 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <!-- An h2 under the DealHeader's h1: the deal is the page. -->
            <Heading
                variant="small"
                title="Tasks"
                description="The work this deal owes, in the order the workflow asks for it."
            />
            <AppButton v-if="canManage" @click="addTask(null)">
                <Plus class="size-4" aria-hidden="true" />
                Add task
            </AppButton>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <SegmentedControl v-model="view" :segments="segments" />

            <!--
                §11: never colour alone — the badge carries its word. Both are
                counted over open tasks only, because a task completed late is
                not overdue: there is nothing left to do.
            -->
            <StatusBadge
                v-if="counts.overdue > 0"
                tone="danger"
                :label="`${counts.overdue} overdue`"
                dotless
            />
            <StatusBadge
                v-if="counts.unassigned > 0"
                tone="warning"
                :label="`${counts.unassigned} unassigned`"
                dotless
            />
        </div>

        <!--
            Two different nothings, and they need different sentences (IA §10:
            an empty state says what belongs here). A deal with no tasks at all
            is usually a deal with no workflow attached yet, and the fix is a
            different screen; a filter that matches nothing is the reader's own
            checklist, finished.
        -->
        <EmptyState
            v-if="groups.length === 0"
            :icon="ListChecks"
            title="No tasks on this deal yet"
            description="Attaching a workflow brings its checklist with it. You can also add a one-off task that belongs to no stage."
        >
            <template v-if="canManage" #action>
                <AppButton @click="addTask(null)">Add task</AppButton>
            </template>
        </EmptyState>

        <EmptyState
            v-else-if="shownGroups.length === 0"
            :icon="ListChecks"
            :title="
                view === 'open'
                    ? 'Nothing open on this deal'
                    : 'Nothing completed yet'
            "
            :description="
                view === 'open'
                    ? 'Every task on every stage is complete. Advancing the deal is the next move.'
                    : 'Tasks appear here once somebody completes them.'
            "
        />

        <Card v-for="group in shownGroups" :key="group.key">
            <template #header>
                <div class="flex min-w-0 flex-col">
                    <h3
                        class="truncate text-13 font-semibold text-card-foreground"
                    >
                        {{ group.stageName ?? 'Not tied to a stage' }}
                    </h3>
                    <!--
                        The workflow, on every group rather than only when a
                        deal has two: F4.7 makes two the ordinary case, and
                        "Photography" means something different under
                        Pre-Listing than under Under Contract.
                    -->
                    <span
                        v-if="group.workflowName"
                        class="truncate text-[11px] text-muted-foreground"
                        >{{ group.workflowName }}</span
                    >
                    <span
                        v-else
                        class="truncate text-[11px] text-muted-foreground"
                        >On the deal, outside any stage</span
                    >
                </div>
            </template>

            <!--
                A record fact — where the workflow is — not an evaluation of
                whether it can move. `stages.state` is a cache only an advance
                attempt refreshes, so a state badge here would be stale or
                would cost this screen every gate on the deal to draw a label
                nobody came for. The timeline (S16) is the screen that answers
                that, live.
            -->
            <template v-if="group.isCurrent" #badge>
                <StatusBadge tone="info" label="Current stage" dotless />
            </template>

            <template v-if="canManage && group.stageId" #action>
                <AppButton
                    variant="ghost"
                    size="compact"
                    @click="addTask(group.stageId)"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    Add task
                </AppButton>
            </template>

            <ul class="flex flex-col">
                <li v-for="task in group.tasks" :key="task.id">
                    <TaskItem
                        :title="task.title"
                        :meta="metaFor(task)"
                        :completed="task.state === 'completed'"
                        :due-date="task.dueDate"
                        :assignee="
                            task.assigneeName
                                ? { name: task.assigneeName }
                                : null
                        "
                        :readonly="!canManage"
                        @update:completed="setCompleted(task, $event)"
                    >
                        <!--
                            The label names the task, because it is the
                            accessible name of the control and there are forty
                            of these on the page: "Edit task" forty times over
                            tells a screen-reader user which button they are on
                            and nothing about which task. `IconButton` puts the
                            same string in `title`, so the pointer tooltip
                            gains it too.
                        -->
                        <template v-if="canManage" #actions>
                            <IconButton
                                :icon="Pencil"
                                :label="`Edit ${task.title}`"
                                @click="editTask(task)"
                            />
                            <!--
                                Hidden below `sm`: see `TaskItem`'s note on the
                                avatar. On a phone the row's horizontal budget
                                goes to the title and the two controls somebody
                                actually uses there — the checkbox and Edit.
                            -->
                            <IconButton
                                v-if="canDelete(task)"
                                :icon="Trash2"
                                :label="`Delete ${task.title}`"
                                class="hidden sm:inline-flex"
                                @click="deleteTask(task)"
                            />
                        </template>
                    </TaskItem>
                </li>
            </ul>
        </Card>

        <TaskFormDialog
            v-model:open="editorOpen"
            :deal-id="dealHeader.id"
            :task="editing"
            :default-stage-id="editorStageId"
            :assignees="assignees"
            :stage-options="stageOptions"
            :can-delete="editingTask ? canDelete(editingTask) : false"
            @delete="deleteFromDialog"
        />
    </div>
</template>
