<script setup lang="ts">
/**
 * S42's task rows, made editable (PRD F4.1 · issues #86, #87, #11).
 *
 * ## The gap this closes
 *
 * `is_required`, `due_offset_days` and `owner_role` are the columns #11 lists
 * as missing from #154's checklist, and until this dialog the only way to
 * change one was to delete the task and add it again — ninety times, losing
 * its place in the order each time. The metadata ended up gathered in a GitHub
 * comment because the screen could not take it, which is what makes #87 a
 * content blocker rather than a mechanism one.
 *
 * ## Editing this cannot reach a deal already running
 *
 * PRD §8.1's template/instance split: `InstantiateWorkflow` snapshots when a
 * workflow starts, so nothing here touches a task somebody is working on.
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

export type TaskTemplateValues = {
    id: string;
    title: string;
    description: string | null;
    ownerRole: string | null;
    dueOffsetDays: number | null;
    isRequired: boolean;
};

const props = defineProps<{
    open: boolean;
    templateId: string;
    stageTemplateId: string;
    /** The task being edited, or null when this is an add. */
    task: TaskTemplateValues | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

/*
 * `due_offset_days` is held as a **string**, not a number — it is
 * `nullable|integer|between:-365,365` on the server and this is a text input,
 * so the value in hand between two keystrokes is a string, including the empty
 * one, which is *no answer* rather than zero. `submit()` converts.
 *
 * The two nullable strings are held as `''`: a text input has no way to say
 * null, and `ConvertEmptyStringsToNull` (Laravel's global middleware, which
 * `bootstrap/app.php` does not remove) turns `''` back into null on the way
 * in, which is what `nullable|string` wants.
 */
const form = useForm<{
    title: string;
    description: string;
    owner_role: string;
    due_offset_days: string;
    is_required: boolean;
}>({
    title: '',
    description: '',
    owner_role: '',
    due_offset_days: '',
    /*
     * False, which is the column's own default and the conservative one for a
     * flag that decides whether a stage can advance. #11 settles it with the
     * example: *"Bring client gift for inspection"* and *"Confirm loan
     * application completed with lender"* cannot both be blocking.
     */
    is_required: false,
});

/*
 * Filled on open rather than on mount. One dialog is mounted for the screen
 * and reused for every row, so reopening it on a second task must not show the
 * first one's answers — and a stale `is_required` is the worst of them,
 * because it decides what stops a deal.
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
        form.owner_role = props.task?.ownerRole ?? '';
        form.due_offset_days = fromNullableInteger(props.task?.dueOffsetDays);
        form.is_required = props.task?.isRequired ?? false;
    },
    { immediate: true },
);

/**
 * A stored `null` becomes an empty box, never the word "null" or a `0`.
 *
 * `String(null)` is `'null'` and `Number(null)` is `0`; both put an answer in
 * a field nobody answered.
 */
function fromNullableInteger(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : String(value);
}

/**
 * An empty box is **no answer**, not zero.
 *
 * `Number('')` is `0` — the trap `CLAUDE.md` records from #107, where clearing
 * a numeric field *added* a zero rather than emptying it. Here that zero means
 * "due on the day the stage starts", which is a deadline nobody typed. So the
 * value is trimmed and tested for empty **before** `Number` sees it.
 *
 * A value that is not an integer is handed back **as the string it was**
 * rather than as `NaN`: `JSON.stringify(NaN)` is `null`, which passes
 * `nullable` and would save *no answer* over somebody's typo. The raw string
 * fails the server's `integer` rule instead, which is a message on the field.
 *
 * A near-copy of the same helper in `StageTemplateDialog` — two callers rather
 * than the three that earn a shared module, and the whole rule is four lines
 * with its reasoning attached.
 */
function toNullableInteger(value: string): number | string | null {
    const trimmed = value.trim();

    if (trimmed === '') {
        return null;
    }

    const parsed = Number(trimmed);

    return Number.isInteger(parsed) ? parsed : trimmed;
}

const base = computed(
    () =>
        `/templates/${props.templateId}/stages/${props.stageTemplateId}/tasks`,
);

function submit(): void {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('update:open', false),
    };

    form.transform((data) => ({
        ...data,
        due_offset_days: toNullableInteger(data.due_offset_days),
    }));

    if (props.task) {
        form.patch(`${base.value}/${props.task.id}`, options);

        return;
    }

    form.post(base.value, options);
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <!-- IA §7: **Add** attaches to a parent, **Edit** changes. -->
                <DialogTitle>{{ task ? 'Edit task' : 'Add task' }}</DialogTitle>
                <DialogDescription>
                    Something somebody does during this stage. Deals already
                    running keep the tasks they started with.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label for="task_template_title">Task</Label>
                    <AppInput
                        id="task_template_title"
                        v-model="form.title"
                        maxlength="200"
                        placeholder="Order the survey"
                    />
                    <p
                        v-if="form.errors.title"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.title }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="task_template_description">Description</Label>
                    <AppTextarea
                        id="task_template_description"
                        v-model="form.description"
                        :rows="3"
                        maxlength="2000"
                    />
                    <p class="text-[11px] text-muted-foreground">
                        How it is done, for whoever picks it up. Optional.
                    </p>
                    <p
                        v-if="form.errors.description"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.description }}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="task_template_owner">Who owns it</Label>
                        <AppInput
                            id="task_template_owner"
                            v-model="form.owner_role"
                            maxlength="120"
                            placeholder="Transaction coordinator"
                        />
                        <!--
                            #64: a role and never a person, for the same reason
                            the stage carries one — a template travels between
                            teams and `roles` is per-team.
                        -->
                        <p class="text-[11px] text-muted-foreground">
                            A role, not a person — a template travels between
                            teams, and the role is matched to somebody when a
                            deal starts.
                        </p>
                        <p
                            v-if="form.errors.owner_role"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.owner_role }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="task_template_due">When it is due</Label>
                        <AppInput
                            id="task_template_due"
                            v-model="form.due_offset_days"
                            inputmode="numeric"
                            placeholder="-3"
                        />
                        <!--
                            Signed, and stage-relative: "chase the survey three
                            days before" is an ordinary instruction. A separate
                            before/after toggle would be a second control
                            holding half of one number.
                        -->
                        <p class="text-[11px] text-muted-foreground">
                            Days from the stage's start. Negative is before it.
                            Leave it empty when there is no deadline.
                        </p>
                        <p
                            v-if="form.errors.due_offset_days"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.due_offset_days }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="flex items-center gap-2 text-13">
                        <input v-model="form.is_required" type="checkbox" />
                        Required before the stage can advance
                    </label>
                    <!--
                        Design System §12: a consequential input carries its
                        consequence beneath it, and the consequence has to be
                        true. A required task feeds the `required_tasks_complete`
                        gate — which only stops the advance where a stage
                        carries that gate, so the sentence says "where this
                        stage has a required-tasks gate" rather than promising
                        something the flag cannot do on its own.
                    -->
                    <p class="text-[11px] text-muted-foreground">
                        Where this stage carries a required-tasks gate, a
                        required task that is not done stops the deal advancing.
                        Most tasks are not required.
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
                        type="button"
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
