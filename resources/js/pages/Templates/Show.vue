<script setup lang="ts">
/**
 * S41, S42, S43 — the workflow template editor, and the stage and gate
 * editors under it (PRD F4.1 · issues #85, #86, #87).
 *
 * ## Editing this cannot reach a deal already running
 *
 * PRD §7.1 calls the template/instance split *"the highest-impact
 * correction"* in the document, and `InstantiateWorkflow` snapshots at the
 * moment a workflow starts. Nothing on this screen touches the runtime layer.
 * The line under the heading says so, because a team that believes editing a
 * template fixes a live deal will edit a template instead of fixing the deal.
 *
 * ## A system template is readable and never editable
 *
 * One pack is shared by every team. Controls are **absent** rather than
 * disabled, per §7.3 and the rule Frontend conventions §4 records — and the
 * way to change one is to take a copy from the index.
 *
 * ## Everything is editable in place now, and that is #87's whole point
 *
 * Stages, gates and tasks were add-and-remove only, and three columns a
 * seeded pack needs had no control at all: a task's `owner_role`, its
 * `description`, and a stage's `owner_role`. #11's markup pass over #154's
 * ninety-item checklist — who owns each task, when it is due, whether it
 * gates an advance — was therefore something to do in a GitHub comment rather
 * than in the running product, which is the wrong way round: the person who
 * can answer those questions is looking at this screen.
 *
 * Correcting one flag on one task used to mean deleting the task and adding
 * it back, at the end of the list. So the inline add-forms became dialogs
 * that do both — the shape `AutomationDialog` already set on this page, and
 * the one Frontend conventions §2 rule 6 asks for once a pattern is used
 * three times.
 *
 * ## Reordering has no drag library
 *
 * Design System §13.2's note, decided in S38: explicit move controls, and the
 * whole order sent at once because a reorder is one intention. The arithmetic
 * is `lib/reorder.ts` rather than three copies here — `calendarNavigation`'s
 * finding, which is that a copy stays green after the original is fixed.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AutomationDialog from '@/components/app/AutomationDialog.vue';
import type {
    AutomationShape,
    AutomationValues,
} from '@/components/app/AutomationDialog.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import GateTemplateDialog from '@/components/app/GateTemplateDialog.vue';
import type { GateTemplateValues } from '@/components/app/GateTemplateDialog.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StageTemplateDialog from '@/components/app/StageTemplateDialog.vue';
import type { StageTemplateValues } from '@/components/app/StageTemplateDialog.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TaskTemplateDialog from '@/components/app/TaskTemplateDialog.vue';
import type { TaskTemplateValues } from '@/components/app/TaskTemplateDialog.vue';
import { formatCount } from '@/lib/formatters';
import { moveWithin } from '@/lib/reorder';

type Stage = StageTemplateValues & {
    sortOrder: number;
    gates: GateTemplateValues[];
    tasks: TaskTemplateValues[];
    automations: AutomationValues[];
};

const props = defineProps<{
    template: {
        id: string;
        name: string;
        description: string | null;
        isActive: boolean;
        isSystem: boolean;
        inUse: number;
        stages: Stage[];
    };
    /**
     * What a person may **pick**, from `GateRegistry::selectableOptions()` —
     * the types S43 can fully specify. Five of the seven evaluators read a
     * `configuration` this editor has no fields for, and a gate composed
     * without one is a stage only an override can pass.
     */
    gateTypes: Record<string, string>;
    /**
     * What every type is **called**, from `GateRegistry::options()`. Reading
     * and composing are different questions: a gate a pack carries renders
     * with its name whether or not this screen could have built it.
     */
    gateTypeLabels: Record<string, string>;
    /** S44's pickers (#91), selectable rather than complete — see the controller. */
    automationTriggers: Record<string, string>;
    automationActions: Record<string, string>;
    /** Which actions need words, and on which channel. */
    automationShapes: Record<string, AutomationShape>;
    messageTemplates: { id: string; name: string; channel: string }[];
    can: { update: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Templates', href: '/templates' }],
    },
});

const base = `/templates/${props.template.id}`;

const inUseLabel = computed(() =>
    props.template.inUse === 1
        ? '1 deal running on it'
        : `${props.template.inUse} deals running on it`,
);

/*
 * One dialog of each kind for the whole screen, rather than one per stage.
 *
 * Which stage it is working on travels in a ref beside the record being
 * edited — the shape `AutomationDialog` set here first. A dialog per stage
 * would be four mounted modals holding four dirty forms for a control
 * somebody uses one at a time, and `useForm` is a single reactive object, so
 * the inline form this replaced put one typed title into every stage's box.
 */
const stageOpen = ref(false);
const editingStage = ref<StageTemplateValues | null>(null);

const gateOpen = ref(false);
const gateStage = ref<Stage | null>(null);
const editingGate = ref<GateTemplateValues | null>(null);

const taskOpen = ref(false);
const taskStage = ref<Stage | null>(null);
const editingTask = ref<TaskTemplateValues | null>(null);

const automationStage = ref<Stage | null>(null);
const editingAutomation = ref<AutomationValues | null>(null);
const automationOpen = ref(false);

function openStage(stage: StageTemplateValues | null): void {
    editingStage.value = stage;
    stageOpen.value = true;
}

function openGate(stage: Stage, gate: GateTemplateValues | null): void {
    gateStage.value = stage;
    editingGate.value = gate;
    gateOpen.value = true;
}

function openTask(stage: Stage, task: TaskTemplateValues | null): void {
    taskStage.value = stage;
    editingTask.value = task;
    taskOpen.value = true;
}

function openAutomation(
    stage: Stage,
    automation: AutomationValues | null,
): void {
    automationStage.value = stage;
    editingAutomation.value = automation;
    automationOpen.value = true;
}

/**
 * The types this dialog may offer, given what it is opening onto.
 *
 * `gateTypes` is the narrow list — what a person may *choose*, because S43 has
 * no editor for five of the seven configurations. But a pack file may carry
 * any type the registry knows (#87), so a team can hold a gate whose type is
 * not in that list. Offering only the narrow list would open the form on a
 * select with no matching option and refuse the Save for a value nobody
 * touched, so the stored type is added back — by name, from `gateTypeLabels`,
 * which is what the server sends precisely so a pack's gate reads as itself.
 *
 * The server allows the same one value for the same reason, so this cannot
 * offer something a save would refuse.
 */
function gateTypesFor(gate: GateTemplateValues | null): Record<string, string> {
    if (!gate || gate.gateType in props.gateTypes) {
        return props.gateTypes;
    }

    return {
        ...props.gateTypes,
        [gate.gateType]: props.gateTypeLabels[gate.gateType] ?? gate.gateType,
    };
}

/**
 * Send a whole new order, or do nothing when the move runs off the end.
 *
 * `moveWithin` returns null rather than the unchanged list precisely so this
 * cannot post a no-op — the buttons at the ends are disabled, and a keyboard
 * or a stale render still reaches here.
 */
function reorder(url: string, ids: string[], index: number, by: number): void {
    const moved = moveWithin(ids, index, by);

    if (moved === null) {
        return;
    }

    router.patch(
        url,
        { ids: moved },
        {
            preserveScroll: true,
            /*
             * The server refuses a reorder that does not name the whole set,
             * which is what a page drawn before a colleague added a row sends.
             * Reloading is the fix a person would make by hand: the list comes
             * back current and the next move works.
             */
            onError: () => router.reload({ only: ['template'] }),
        },
    );
}

function moveStage(index: number, by: number): void {
    reorder(
        `${base}/stages`,
        props.template.stages.map((stage) => stage.id),
        index,
        by,
    );
}

function moveGate(stage: Stage, index: number, by: number): void {
    reorder(
        `${base}/stages/${stage.id}/gates`,
        stage.gates.map((gate) => gate.id),
        index,
        by,
    );
}

function moveTask(stage: Stage, index: number, by: number): void {
    reorder(
        `${base}/stages/${stage.id}/tasks`,
        stage.tasks.map((task) => task.id),
        index,
        by,
    );
}

function removeStage(stage: Stage): void {
    // IA §10: name the object and the consequence — and say what is *not* a
    // consequence, because it is the thing people fear here.
    if (
        !window.confirm(
            `Remove ${stage.name} from this template? Deals already running keep theirs.`,
        )
    ) {
        return;
    }

    router.delete(`${base}/stages/${stage.id}`, { preserveScroll: true });
}

function removeGate(stage: Stage, gate: GateTemplateValues): void {
    router.delete(`${base}/stages/${stage.id}/gates/${gate.id}`, {
        preserveScroll: true,
    });
}

function removeTask(stage: Stage, task: TaskTemplateValues): void {
    router.delete(`${base}/stages/${stage.id}/tasks/${task.id}`, {
        preserveScroll: true,
    });
}

function removeAutomation(stage: Stage, automation: AutomationValues): void {
    // What is *not* a consequence is the part people fear here, so it is said.
    if (
        !window.confirm(
            `Remove this automation? Deals already running keep theirs. ${automation.description}`,
        )
    ) {
        return;
    }

    router.delete(`${base}/stages/${stage.id}/automations/${automation.id}`, {
        preserveScroll: true,
    });
}

/**
 * What a stage says about itself under its name.
 *
 * Composed here rather than in the markup so the three optional parts do not
 * become three conditional spans with their own separators — and so a stage
 * that is a milestone with no client-facing label reads as one rather than as
 * "Milestone · " with nothing after it. That case is not cosmetic:
 * `ClientStatus` **omits** a stage with no label from the client's page
 * rather than inventing words for it.
 */
function stageDetail(stage: Stage): string {
    const parts: string[] = [];

    if (stage.ownerRole) {
        parts.push(stage.ownerRole);
    }

    /*
     * Shown because the dialog collects it and the help text says it "sets the
     * planned dates on a deal" — a field somebody can set and cannot see
     * without reopening the form is the same complaint #11 is about, one field
     * along.
     */
    if (stage.expectedDurationDays !== null) {
        parts.push(formatCount(stage.expectedDurationDays, 'day'));
    }

    if (stage.isMilestone) {
        parts.push(
            stage.clientFacingLabel
                ? `Milestone · ${stage.clientFacingLabel}`
                : 'Milestone · no client-facing wording yet',
        );
    }

    return parts.join(' · ');
}

/**
 * What a task says about itself, on the same principle.
 *
 * The offset is spelled out rather than shown as a signed number: "-3" on a
 * row means nothing without the sentence that explains the sign, and the
 * sentence does not fit on the row.
 */
function taskDetail(task: TaskTemplateValues): string {
    const parts: string[] = [];

    if (task.ownerRole) {
        parts.push(task.ownerRole);
    }

    if (task.dueOffsetDays !== null) {
        const days = Math.abs(task.dueOffsetDays);
        const unit = days === 1 ? 'day' : 'days';

        parts.push(
            task.dueOffsetDays < 0
                ? `${days} ${unit} before the stage starts`
                : `${days} ${unit} in`,
        );
    }

    return parts.join(' · ');
}

/*
 * Renaming and removing the template itself. Without these the screen could
 * build a process and never correct its name — and "Listing to Close (copy)",
 * which is what taking a copy from a pack produces, is a name somebody wants
 * to change within a minute of arriving here.
 */
const renaming = ref(false);
const detailsForm = useForm({
    name: props.template.name,
    description: props.template.description ?? '',
});

function rename(): void {
    detailsForm.patch(base, {
        preserveScroll: true,
        onSuccess: () => {
            renaming.value = false;
        },
    });
}

function remove(): void {
    /*
     * The count in the question rather than in a report afterwards — the rule
     * the archived-lookup pattern records, and the reassuring direction of it
     * stated plainly: those deals keep running, because instantiation
     * snapshotted.
     */
    const running =
        props.template.inUse > 0
            ? ` ${inUseLabel.value} — they keep the stages they started with and are not affected.`
            : '';

    if (!window.confirm(`Remove ${props.template.name}?${running}`)) {
        return;
    }

    router.delete(base);
}
</script>

<template>
    <Head :title="template.name" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :title="template.name"
            subtitle="Editing this changes what happens next time. Deals already running keep the stages they started with."
        >
            <template #actions>
                <StatusBadge
                    v-if="template.isSystem"
                    tone="neutral"
                    label="From a pack"
                    dotless
                />
                <!--
                    Absent on a pack's template rather than disabled, the way
                    every other control on this screen is.
                -->
                <template v-if="can.update">
                    <AppButton
                        variant="ghost"
                        size="compact"
                        @click="renaming = !renaming"
                        >{{ renaming ? 'Cancel' : 'Rename' }}</AppButton
                    >
                    <AppButton variant="ghost" size="compact" @click="remove"
                        >Remove</AppButton
                    >
                </template>
                <!--
                    The count before the edit, and the reassuring direction of
                    it: those deals will *not* change, which is why the number
                    is worth showing rather than a reason to refuse the edit.
                -->
                <StatusBadge
                    v-if="template.inUse > 0"
                    tone="neutral"
                    :label="inUseLabel"
                    dotless
                />
            </template>
        </PageHeader>

        <!--
            A system template is readable and never editable. Said once, here,
            rather than by twenty disabled controls.
        -->
        <p
            v-if="template.isSystem"
            class="rounded-md border bg-muted px-4 py-2.5 text-xs text-muted-foreground"
        >
            This one belongs to a pack every team shares, so it cannot be
            changed. Take a copy from the templates list and change that.
        </p>

        <Card v-if="renaming" title="Rename this template">
            <form
                class="flex flex-wrap items-end gap-2 px-4 py-4"
                @submit.prevent="rename"
            >
                <AppInput
                    v-model="detailsForm.name"
                    class="w-72"
                    maxlength="120"
                />
                <AppInput
                    v-model="detailsForm.description"
                    class="w-96"
                    maxlength="2000"
                    placeholder="What this process is for"
                />
                <AppButton :disabled="detailsForm.processing" @click="rename"
                    >Save</AppButton
                >
                <!--
                    Rendered, because a refusal nobody can see is the failure
                    IA §10 names: the field keeps what was typed, the save does
                    not happen, and no reason appears.
                -->
                <p
                    v-if="
                        detailsForm.errors.name ||
                        detailsForm.errors.description
                    "
                    class="w-full text-xs text-state-danger"
                >
                    {{
                        detailsForm.errors.name ??
                        detailsForm.errors.description
                    }}
                </p>
            </form>
        </Card>

        <Card title="Stages">
            <EmptyState
                v-if="template.stages.length === 0"
                title="No stages yet"
                description="A stage is a period of the deal — it holds the tasks to do and the gates that must clear before it can advance."
            />

            <ul v-else class="flex flex-col">
                <li
                    v-for="(stage, index) in template.stages"
                    :key="stage.id"
                    class="flex flex-col gap-2 border-b px-4 py-3 last:border-b-0"
                >
                    <div class="flex items-center gap-3">
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-13 font-medium">{{
                                stage.name
                            }}</span>
                            <span
                                v-if="stageDetail(stage)"
                                class="truncate text-[11px] text-muted-foreground"
                                >{{ stageDetail(stage) }}</span
                            >
                        </span>

                        <template v-if="can.update">
                            <AppButton
                                variant="ghost"
                                size="compact"
                                :disabled="index === 0 || undefined"
                                :aria-label="`Move ${stage.name} up`"
                                @click="moveStage(index, -1)"
                            >
                                <ChevronUp
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                size="compact"
                                :disabled="
                                    index === template.stages.length - 1 ||
                                    undefined
                                "
                                :aria-label="`Move ${stage.name} down`"
                                @click="moveStage(index, 1)"
                            >
                                <ChevronDown
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                size="compact"
                                :aria-label="`Edit ${stage.name}`"
                                @click="openStage(stage)"
                                >Edit</AppButton
                            >
                            <AppButton
                                variant="ghost"
                                size="compact"
                                :aria-label="`Remove ${stage.name}`"
                                @click="removeStage(stage)"
                                >Remove</AppButton
                            >
                        </template>
                    </div>

                    <!--
                        Gates as rows rather than a row of badges, because
                        every one of them now has an Edit beside it. The badge
                        carries the type, which is what decides the evaluator.
                    -->
                    <ul
                        v-if="stage.gates.length > 0"
                        class="flex flex-col gap-1"
                    >
                        <li
                            v-for="(gate, gateIndex) in stage.gates"
                            :key="gate.id"
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <StatusBadge
                                :tone="gate.isBlocking ? 'warning' : 'neutral'"
                                :label="`${gate.label} · ${gateTypeLabels[gate.gateType] ?? gate.gateType}`"
                                dotless
                            />
                            <span class="min-w-0 flex-1"></span>
                            <template v-if="can.update">
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :disabled="gateIndex === 0 || undefined"
                                    :aria-label="`Move ${gate.label} up`"
                                    @click="moveGate(stage, gateIndex, -1)"
                                >
                                    <ChevronUp
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </AppButton>
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :disabled="
                                        gateIndex === stage.gates.length - 1 ||
                                        undefined
                                    "
                                    :aria-label="`Move ${gate.label} down`"
                                    @click="moveGate(stage, gateIndex, 1)"
                                >
                                    <ChevronDown
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </AppButton>
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :aria-label="`Edit ${gate.label}`"
                                    @click="openGate(stage, gate)"
                                    >Edit</AppButton
                                >
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :aria-label="`Remove ${gate.label}`"
                                    @click="removeGate(stage, gate)"
                                    >×</AppButton
                                >
                            </template>
                        </li>
                    </ul>

                    <ul
                        v-if="stage.tasks.length > 0"
                        class="flex flex-col gap-1"
                    >
                        <li
                            v-for="(task, taskIndex) in stage.tasks"
                            :key="task.id"
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="truncate">{{ task.title }}</span>
                                <span
                                    v-if="taskDetail(task)"
                                    class="truncate text-[11px]"
                                    >{{ taskDetail(task) }}</span
                                >
                            </span>
                            <!--
                                What makes the task gate an advance, so it is
                                a badge rather than a word: it is the one
                                thing on this row with a consequence.
                            -->
                            <StatusBadge
                                v-if="task.isRequired"
                                tone="warning"
                                label="Required"
                                dotless
                            />
                            <template v-if="can.update">
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :disabled="taskIndex === 0 || undefined"
                                    :aria-label="`Move ${task.title} up`"
                                    @click="moveTask(stage, taskIndex, -1)"
                                >
                                    <ChevronUp
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </AppButton>
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :disabled="
                                        taskIndex === stage.tasks.length - 1 ||
                                        undefined
                                    "
                                    :aria-label="`Move ${task.title} down`"
                                    @click="moveTask(stage, taskIndex, 1)"
                                >
                                    <ChevronDown
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </AppButton>
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :aria-label="`Edit ${task.title}`"
                                    @click="openTask(stage, task)"
                                    >Edit</AppButton
                                >
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :aria-label="`Remove ${task.title}`"
                                    @click="removeTask(stage, task)"
                                    >×</AppButton
                                >
                            </template>
                        </li>
                    </ul>

                    <!--
                        S44 — what happens by itself on this stage (#91).
                        Below the tasks because it is the least-often edited of
                        the three, and because an automation usually refers to
                        a requirement or a task that has to exist first.
                    -->
                    <ul
                        v-if="stage.automations.length > 0"
                        class="flex flex-col gap-1"
                    >
                        <li
                            v-for="automation in stage.automations"
                            :key="automation.id"
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                automation.description
                            }}</span>
                            <!--
                                An automation that sends words and has none —
                                reachable, because hard-deleting a template
                                nulls the pointer rather than cascading. Shown
                                rather than left to look like it will run.
                            -->
                            <StatusBadge
                                v-if="!automation.isComplete"
                                tone="warning"
                                label="Needs a template"
                                dotless
                            />
                            <StatusBadge
                                v-else-if="
                                    automation.executionMode === 'approval'
                                "
                                tone="info"
                                label="Needs approving"
                                dotless
                            />
                            <StatusBadge
                                v-else-if="
                                    automation.executionMode === 'manual'
                                "
                                tone="neutral"
                                label="Prompt"
                                dotless
                            />
                            <StatusBadge
                                v-if="!automation.isActive"
                                tone="neutral"
                                label="Off"
                                dotless
                            />
                            <template v-if="can.update">
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :aria-label="`Edit ${automation.description}`"
                                    @click="openAutomation(stage, automation)"
                                    >Edit</AppButton
                                >
                                <AppButton
                                    variant="ghost"
                                    size="compact"
                                    :aria-label="`Remove ${automation.description}`"
                                    @click="removeAutomation(stage, automation)"
                                    >×</AppButton
                                >
                            </template>
                        </li>
                    </ul>

                    <div v-if="can.update" class="flex flex-wrap gap-2">
                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="openGate(stage, null)"
                            >Add gate</AppButton
                        >
                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="openTask(stage, null)"
                            >Add task</AppButton
                        >
                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="openAutomation(stage, null)"
                            >Add automation</AppButton
                        >
                    </div>
                </li>
            </ul>

            <div v-if="can.update" class="border-t px-4 py-3">
                <AppButton size="compact" @click="openStage(null)"
                    >Add stage</AppButton
                >
            </div>
        </Card>

        <StageTemplateDialog
            v-model:open="stageOpen"
            :template-id="template.id"
            :stage="editingStage"
        />

        <GateTemplateDialog
            v-if="gateStage"
            v-model:open="gateOpen"
            :template-id="template.id"
            :stage-template-id="gateStage.id"
            :gate="editingGate"
            :gate-types="gateTypesFor(editingGate)"
        />

        <TaskTemplateDialog
            v-if="taskStage"
            v-model:open="taskOpen"
            :template-id="template.id"
            :stage-template-id="taskStage.id"
            :task="editingTask"
        />

        <AutomationDialog
            v-if="automationStage"
            v-model:open="automationOpen"
            :template-id="template.id"
            :stage-template-id="automationStage.id"
            :automation="editingAutomation"
            :triggers="automationTriggers"
            :actions="automationActions"
            :shapes="automationShapes"
            :message-templates="messageTemplates"
            :gates="
                automationStage.gates.map((gate) => ({
                    id: gate.id,
                    label: gate.label,
                }))
            "
        />
    </div>
</template>
