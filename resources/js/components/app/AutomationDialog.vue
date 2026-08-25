<script setup lang="ts">
/**
 * S44 — the automation editor (PRD §4.5 F5.1–F5.4, F5.10 · issue #91).
 *
 * ## Why this is a form that narrows rather than four dropdowns
 *
 * The Screen Inventory calls this one of the two screens with no obvious
 * precedent, for a specific reason: *"Trigger, action, recipient rule, all
 * interdependent."* A "days before a key date" trigger needs a key date that
 * exists; a push has no subject; a manual prompt has no recipient rule. Four
 * independent dropdowns can be combined into nonsense, so each choice here
 * decides which question comes next — and `SaveAutomationRequest` refuses the
 * same combinations on the server, because a form is a suggestion.
 *
 * ## How it runs is one choice, not two checkboxes
 *
 * F5.4's manual prompt and F5.7's approval queue are the same moment from two
 * ends — a human in the loop — so this offers one three-way choice. The table
 * carries the same invariant as a CHECK constraint, because two booleans have
 * four states and two of them are nonsense.
 *
 * **Needs approving first** is the arrangement F5.10 describes and the one to
 * reach for: the milestone prepares the email with the right recipient and the
 * right words, and a person releases it. Emily on the competitor: *"it's time
 * to schedule inspection, it auto populates the email."*
 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
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

export type AutomationValues = {
    id: string;
    trigger: string;
    actionType: string;
    messageTemplateId: string | null;
    config: Record<string, unknown>;
    executionMode: string;
    isActive: boolean;
    description: string;
    isComplete: boolean;
};

export type AutomationShape = {
    needsMessageTemplate: boolean;
    /** Which channel a template has to be on, or null when none is needed. */
    channel: string | null;
    isManual: boolean;
};

const props = defineProps<{
    open: boolean;
    templateId: string;
    stageTemplateId: string;
    /** The automation being edited, or null when this is an add. */
    automation: AutomationValues | null;
    triggers: Record<string, string>;
    actions: Record<string, string>;
    shapes: Record<string, AutomationShape>;
    messageTemplates: { id: string; name: string; channel: string }[];
    /** This stage's requirements — the only ones a gate trigger may name. */
    gates: { id: string; label: string }[];
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm<{
    trigger: string;
    action_type: string;
    message_template_id: string | null;
    executionMode: string;
    config: {
        /*
         * Every key present and nullable rather than optional. `AppSelect`
         * models `string | null`, and an optional key types as
         * `string | undefined` — which is the same value at runtime and a
         * different one to the compiler.
         */
        gateTemplateId: string | null;
        taskTitle: string;
        taskDueOffsetDays: string;
        instruction: string;
    };
    is_active: boolean;
}>({
    trigger: '',
    action_type: '',
    message_template_id: null,
    executionMode: 'automatic',
    config: {
        gateTemplateId: null,
        taskTitle: '',
        taskDueOffsetDays: '',
        instruction: '',
    },
    is_active: true,
});

/*
 * Filled on open rather than on mount. The dialog is mounted once per stage
 * and reused for every row, so reopening it on a second automation must not
 * show the first one's answers — and a stale `executionMode` is the worst of
 * them, because it decides whether an email goes out with nobody looking.
 */
watch(
    () => [props.open, props.automation?.id] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.trigger =
            props.automation?.trigger ?? Object.keys(props.triggers)[0] ?? '';
        form.action_type =
            props.automation?.actionType ?? Object.keys(props.actions)[0] ?? '';
        form.message_template_id = props.automation?.messageTemplateId ?? null;
        form.executionMode = props.automation?.executionMode ?? 'automatic';
        /*
         * Every key filled from the stored config or from a default — never a
         * spread of the stored object behind a cast.
         *
         * A `create_task` automation stores one key, so spreading it leaves
         * the other three `undefined`, which is precisely what the comment on
         * the form's type says must not happen: `AppSelect` models
         * `string | null`, and `undefined` is a different value to the
         * compiler and an unbound control at runtime. The cast said otherwise.
         */
        const stored = props.automation?.config ?? {};

        form.config = {
            gateTemplateId:
                typeof stored.gateTemplateId === 'string'
                    ? stored.gateTemplateId
                    : null,
            taskTitle:
                typeof stored.taskTitle === 'string' ? stored.taskTitle : '',
            taskDueOffsetDays:
                stored.taskDueOffsetDays === undefined ||
                stored.taskDueOffsetDays === null
                    ? ''
                    : String(stored.taskDueOffsetDays),
            instruction:
                typeof stored.instruction === 'string'
                    ? stored.instruction
                    : '',
        };
        form.is_active = props.automation?.isActive ?? true;
    },
    { immediate: true },
);

const shape = computed<AutomationShape | null>(
    () => props.shapes[form.action_type] ?? null,
);

/** `gate_cleared` is the only trigger that carries a second choice today. */
const needsGate = computed(() => form.trigger === 'gate_cleared');

/** Only templates on the channel this action sends. */
const usableTemplates = computed(() =>
    Object.fromEntries(
        props.messageTemplates
            .filter((template) => template.channel === shape.value?.channel)
            .map((template) => [template.id, template.name]),
    ),
);

/**
 * The three-way choice, narrowed by what this action can offer.
 *
 * *Approving* only means something for an action that sends: F5.7 is about
 * releasing a queued message, and there is no sense in which a created task is
 * approved. A manual prompt has only one mode by definition.
 */
const modes = computed<Record<string, string>>(() => {
    if (shape.value?.isManual) {
        return { manual: 'Prompts somebody to do it' };
    }

    const base: Record<string, string> = { automatic: 'Fires on its own' };

    if (shape.value?.needsMessageTemplate) {
        base.approval = 'Needs approving first';
    }

    base.manual = 'Prompts somebody to do it';

    return base;
});

/*
 * A choice that stops being offered must stop being held. Switching from
 * "Send an email" to "Create a task" leaves `approval` selected, and the
 * server refuses it — with an error on a control the reader can no longer see.
 */
watch([shape, modes], () => {
    if (!(form.executionMode in modes.value)) {
        form.executionMode = Object.keys(modes.value)[0] ?? 'automatic';
    }

    if (!shape.value?.needsMessageTemplate) {
        form.message_template_id = null;
    }
});

watch(
    () => form.trigger,
    () => {
        if (!needsGate.value) {
            form.config = { ...form.config, gateTemplateId: null };
        }
    },
);

const base = computed(
    () =>
        `/templates/${props.templateId}/stages/${props.stageTemplateId}/automations`,
);

function submit(): void {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.automation) {
        form.patch(`${base.value}/${props.automation.id}`, options);

        return;
    }

    form.post(base.value, options);
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    automation ? 'Edit automation' : 'Add automation'
                }}</DialogTitle>
                <DialogDescription>
                    Something the product does by itself when this stage reaches
                    a point. Deals already running keep the automations they
                    started with.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label for="automation_trigger">When</Label>
                    <AppSelect
                        id="automation_trigger"
                        v-model="form.trigger"
                        :options="triggers"
                        size="default"
                    />
                    <p
                        v-if="form.errors.trigger"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.trigger }}
                    </p>
                </div>

                <!--
                    Narrowed to this stage's own requirements. One from another
                    stage is an automation that can never fire, and nothing
                    anywhere would say so.
                -->
                <div v-if="needsGate" class="flex flex-col gap-1.5">
                    <Label for="automation_gate">Which requirement</Label>
                    <AppSelect
                        id="automation_gate"
                        v-model="form.config.gateTemplateId"
                        :options="
                            Object.fromEntries(
                                gates.map((gate) => [gate.id, gate.label]),
                            )
                        "
                        size="default"
                        placeholder="Choose a requirement"
                    />
                    <p
                        v-if="gates.length === 0"
                        class="text-[11px] text-muted-foreground"
                    >
                        This stage has no requirements yet, so nothing can clear
                        on it. Add one first.
                    </p>
                    <p
                        v-if="form.errors['config.gateTemplateId']"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors['config.gateTemplateId'] }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="automation_action">Then</Label>
                    <AppSelect
                        id="automation_action"
                        v-model="form.action_type"
                        :options="actions"
                        size="default"
                    />
                    <p
                        v-if="form.errors.action_type"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.action_type }}
                    </p>
                </div>

                <div
                    v-if="shape?.needsMessageTemplate"
                    class="flex flex-col gap-1.5"
                >
                    <Label for="automation_template">Which template</Label>
                    <AppSelect
                        id="automation_template"
                        v-model="form.message_template_id"
                        :options="usableTemplates"
                        size="default"
                        placeholder="Choose a template"
                    />
                    <p
                        v-if="Object.keys(usableTemplates).length === 0"
                        class="text-[11px] text-muted-foreground"
                    >
                        You have no template for this yet. Write one under
                        Templates → Messages, then come back.
                    </p>
                    <p
                        v-if="form.errors.message_template_id"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.message_template_id }}
                    </p>
                </div>

                <div
                    v-if="form.action_type === 'create_task'"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="automation_task">Task</Label>
                        <AppInput
                            id="automation_task"
                            v-model="form.config.taskTitle"
                            size="default"
                            maxlength="200"
                            placeholder="Order the survey"
                        />
                        <p
                            v-if="form.errors['config.taskTitle']"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors['config.taskTitle'] }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="automation_offset">Due, in days</Label>
                        <AppInput
                            id="automation_offset"
                            v-model="form.config.taskDueOffsetDays"
                            size="default"
                            inputmode="numeric"
                        />
                        <!-- Signed, and relative to the stage start: "three
                             days before inspection opens" is a real task. -->
                        <p class="text-[11px] text-muted-foreground">
                            From the stage’s start. Negative is before it.
                        </p>
                    </div>
                </div>

                <div
                    v-if="form.action_type === 'manual_prompt'"
                    class="flex flex-col gap-1.5"
                >
                    <Label for="automation_instruction">What to do</Label>
                    <AppTextarea
                        id="automation_instruction"
                        v-model="form.config.instruction"
                        :rows="3"
                    />
                    <p
                        v-if="form.errors['config.instruction']"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors['config.instruction'] }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="automation_mode">How it runs</Label>
                    <AppSelect
                        id="automation_mode"
                        v-model="form.executionMode"
                        :options="modes"
                        size="default"
                    />
                    <!--
                        Design System §12: a consequential input carries its
                        consequence beneath it. This is the most consequential
                        input in the product — PRD §4.5: *"an automation that
                        emails the wrong client the wrong thing damages a real
                        relationship and cannot be recalled."*
                    -->
                    <p
                        v-if="
                            form.executionMode === 'automatic' &&
                            shape?.needsMessageTemplate
                        "
                        class="text-[11px] text-muted-foreground"
                    >
                        This will send without anybody looking at it first.
                        Nothing sends yet — and when it does, a new team’s
                        messages will wait for approval for their first 30 days
                        whatever this says.
                    </p>
                    <p
                        v-if="form.errors.executionMode"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.executionMode }}
                    </p>
                </div>

                <label class="flex items-center gap-2 text-13">
                    <input v-model="form.is_active" type="checkbox" />
                    Active
                </label>

                <DialogFooter>
                    <AppButton
                        variant="ghost"
                        type="button"
                        @click="emit('update:open', false)"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :disabled="form.processing">{{
                        automation ? 'Save automation' : 'Add automation'
                    }}</AppButton>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
