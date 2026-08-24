<script setup lang="ts">
/**
 * S27 — add and edit a task (PRD §4.4 F4.10 · issue #71).
 *
 * One modal for both, because they are the same six fields and a second form
 * is a second set of answers about which of them are optional. Which verb it
 * is comes from whether a task was handed in.
 *
 * ## What is not in here
 *
 * **Completing.** IA §7 gives Complete its own verb and the server gives it
 * its own route: it writes an activity event and it is what the
 * `required_tasks_complete` gate counts. A checkbox in this form would make
 * finishing the work a side effect of editing its title, and would make it
 * impossible to tick a box without opening a dialog — which is the interaction
 * Heather performs fifty times a deal.
 *
 * **Deleting.** Also its own action, on the row, behind its own confirmation.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppTextarea from '@/components/app/AppTextarea.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export type TaskFormValues = {
    id: string;
    title: string;
    description: string | null;
    stageId: string | null;
    assigneeId: string | null;
    /** What the team calls the assignee — see `assignableTo` below. */
    assigneeName: string | null;
    dueDate: string | null;
    isRequired: boolean;
};

export type StageOptionGroup = {
    workflowName: string;
    stages: { id: string; name: string }[];
};

const props = defineProps<{
    open: boolean;
    dealId: string;
    /** The task being edited, or null when this is an add. */
    task: TaskFormValues | null;
    /** Preselected stage for a new task — the group its button sits in. */
    defaultStageId?: string | null;
    assignees: { id: string; name: string }[];
    stageOptions: StageOptionGroup[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

/**
 * The people this task may be assigned to, plus whoever it already names.
 *
 * The list from the server is live colleagues only — somebody whose membership
 * was revoked keeps the work already assigned to them and cannot be given
 * more. But a select with no option for the value it is holding renders
 * **blank**, which reads as Unassigned and is the opposite of the truth. So
 * the incumbent is added back, said out loud rather than silently: the reader
 * can leave them there or pick somebody who still works here, and the server
 * accepts exactly those two outcomes.
 */
const assignableTo = computed(() => {
    const options = [...props.assignees];

    const incumbent = props.task?.assigneeId;

    if (incumbent && !options.some((person) => person.id === incumbent)) {
        options.unshift({
            id: incumbent,
            name: `${props.task?.assigneeName ?? 'Assigned'} — no longer on the team`,
        });
    }

    return options;
});

const form = useForm({
    title: '',
    description: '',
    stage_id: '',
    assignee_id: '',
    due_date: '',
    is_required: false,
});

/*
 * Filled on open rather than on mount. The dialog is mounted once by the page
 * and reused for every row, so reopening it on a second task must not show the
 * first one's title — and a stale `is_required` is worse than a stale title,
 * because it decides whether a stage can advance.
 */
watch(
    () => [props.open, props.task?.id] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.title = props.task?.title ?? '';
        form.description = props.task?.description ?? '';
        form.stage_id = props.task?.stageId ?? props.defaultStageId ?? '';
        form.assignee_id = props.task?.assigneeId ?? '';
        /*
         * `due_date` arrives as ISO 8601 from the server (IA §10 keeps every
         * date on the wire in one shape) and an `<input type="date">` takes
         * the day half of it. Sliced rather than reformatted: the column is a
         * `date`, so the first ten characters *are* the value, and putting it
         * through a formatter would be putting it through a timezone.
         */
        form.due_date = props.task?.dueDate?.slice(0, 10) ?? '';
        form.is_required = props.task?.isRequired ?? false;
    },
    { immediate: true },
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.task) {
        form.patch(`/deals/${props.dealId}/tasks/${props.task.id}`, options);

        return;
    }

    form.post(`/deals/${props.dealId}/tasks`, options);
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <!-- IA §7: **Add** attaches to a parent, **Edit** changes. -->
                <DialogTitle>{{ task ? 'Edit task' : 'Add task' }}</DialogTitle>
                <DialogDescription>
                    Work owed on this deal. A requirement to advance is a
                    different thing — this is what somebody has to do.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label for="task_title">Task</Label>
                    <AppInput
                        id="task_title"
                        v-model="form.title"
                        required
                        placeholder="Order the sign"
                    />
                    <p
                        v-if="form.errors.title"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.title }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="task_description">Notes</Label>
                    <AppTextarea
                        id="task_description"
                        v-model="form.description"
                        :rows="3"
                    />
                    <p
                        v-if="form.errors.description"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.description }}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="task_assignee">Assign to</Label>
                        <!--
                            IA §7: **Assign**, never Delegate or Allocate.
                            Unassigned is an option rather than a default that
                            cannot be chosen — #71 asks for it to be a visible
                            state, and a picker with no way back to it makes
                            assigning irreversible.
                        -->
                        <select
                            id="task_assignee"
                            v-model="form.assignee_id"
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option value="">Unassigned</option>
                            <option
                                v-for="person in assignableTo"
                                :key="person.id"
                                :value="person.id"
                            >
                                {{ person.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.assignee_id"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.assignee_id }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="task_due_date">Due</Label>
                        <AppInput
                            id="task_due_date"
                            v-model="form.due_date"
                            type="date"
                        />
                        <p
                            v-if="form.errors.due_date"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.due_date }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="task_stage">Stage</Label>
                    <select
                        id="task_stage"
                        v-model="form.stage_id"
                        class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                    >
                        <!--
                            PRD §6.4 makes `stage_id` nullable so an ad-hoc job
                            can sit on the deal outside any stage, which is
                            what this option is.
                        -->
                        <option value="">Not tied to a stage</option>
                        <optgroup
                            v-for="group in stageOptions"
                            :key="group.workflowName"
                            :label="group.workflowName"
                        >
                            <option
                                v-for="stage in group.stages"
                                :key="stage.id"
                                :value="stage.id"
                            >
                                {{ stage.name }}
                            </option>
                        </optgroup>
                    </select>
                    <p
                        v-if="form.errors.stage_id"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.stage_id }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="flex items-center gap-2 text-13">
                        <input v-model="form.is_required" type="checkbox" />
                        Required before the stage can advance
                    </label>
                    <!--
                        Design System §12: a consequential input carries its
                        consequence beneath it. This one decides whether a
                        colleague can move the deal on, and the only way past
                        it is an override — which is audited, and which IA §7
                        keeps for the case where the condition should have been
                        met and was not.
                    -->
                    <!--
                        The consequence, stated accurately. An earlier draft
                        said getting past it "takes an override, which is
                        written to the audit log" — and unticking this box is
                        the other way past, which review on #71 pointed out is
                        exactly the sentence being wrong. Both routes are on
                        the record now, in different places, and the copy says
                        which.
                    -->
                    <p class="text-[11px] text-muted-foreground">
                        A required task blocks the stage’s
                        <strong>requirement to advance</strong> until it is
                        complete. Changing this is recorded on the deal’s
                        activity; waiving the requirement without doing the work
                        is an override, which is written to the audit log.
                    </p>
                    <p
                        v-if="form.errors.is_required"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.is_required }}
                    </p>
                </div>

                <DialogFooter>
                    <AppButton
                        variant="ghost"
                        @click="emit('update:open', false)"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :disabled="form.processing">{{
                        task ? 'Save task' : 'Add task'
                    }}</AppButton>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
