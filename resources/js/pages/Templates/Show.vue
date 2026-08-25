<script setup lang="ts">
/**
 * S41, S42, S43 — the workflow template editor, and the stage and gate
 * editors under it (PRD F4.1 · issues #85, #86).
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
 * ## Reordering has no drag library
 *
 * Design System §13.2's note, decided in S38: explicit move controls, and the
 * whole order sent at once because a reorder is one intention.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';

type Gate = {
    id: string;
    gateType: string;
    label: string;
    isBlocking: boolean;
};

type TaskRow = {
    id: string;
    title: string;
    isRequired: boolean;
    dueOffsetDays: number | null;
};

type Stage = {
    id: string;
    name: string;
    description: string | null;
    sortOrder: number;
    expectedDurationDays: number | null;
    isMilestone: boolean;
    clientFacingLabel: string | null;
    gates: Gate[];
    tasks: TaskRow[];
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

const stageForm = useForm({ name: '' });
const addingTo = ref<string | null>(null);
const gateForm = useForm({
    /*
     * The first key the registry offers, rather than a literal. `EVALUATORS`
     * is ordered with the four that work today first, so the default is one
     * that can actually clear — and a reordering there changes this without
     * anybody having to remember the page exists.
     */
    gate_type: Object.keys(props.gateTypes)[0] ?? '',
    label: '',
});
const taskForm = useForm({ title: '', is_required: true });
/*
 * One form, one stage open at a time — the same pattern `addingTo` uses for
 * gates, and for a reason the gate form found first: `useForm` is a single
 * reactive object, so a form rendered once per stage put the same typed title
 * into every stage's box at once. A form per stage would be the alternative
 * and is worse: four stages is four watchers and four dirty states, for a
 * control somebody uses one at a time.
 */
const addingTaskTo = ref<string | null>(null);

function addStage(): void {
    stageForm.post(`${base}/stages`, {
        preserveScroll: true,
        onSuccess: () => stageForm.reset(),
    });
}

function move(index: number, by: number): void {
    const next = index + by;

    if (next < 0 || next >= props.template.stages.length) {
        return;
    }

    const ids = props.template.stages.map((stage) => stage.id);
    const [moved] = ids.splice(index, 1);

    ids.splice(next, 0, moved);

    router.patch(`${base}/stages`, { ids }, { preserveScroll: true });
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

function addGate(stage: Stage): void {
    gateForm.post(`${base}/stages/${stage.id}/gates`, {
        preserveScroll: true,
        onSuccess: () => {
            gateForm.reset();
            addingTo.value = null;
        },
    });
}

function removeGate(stage: Stage, gate: Gate): void {
    router.delete(`${base}/stages/${stage.id}/gates/${gate.id}`, {
        preserveScroll: true,
    });
}

function addTask(stage: Stage): void {
    taskForm.post(`${base}/stages/${stage.id}/tasks`, {
        preserveScroll: true,
        /*
         * The form stays **open**, unlike the gate one, and the difference is
         * deliberate rather than an oversight: a stage gets one or two gates
         * and four or five tasks, so the common act here is adding several in
         * a row and the common act there is not.
         */
        onSuccess: () => taskForm.reset(),
    });
}

function removeTask(stage: Stage, task: TaskRow): void {
    router.delete(`${base}/stages/${stage.id}/tasks/${task.id}`, {
        preserveScroll: true,
    });
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
                <AppInput v-model="detailsForm.name" class="w-72" />
                <AppInput
                    v-model="detailsForm.description"
                    class="w-96"
                    placeholder="What this process is for"
                />
                <AppButton :disabled="detailsForm.processing" @click="rename"
                    >Save</AppButton
                >
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
                                v-if="stage.isMilestone"
                                class="text-[11px] text-muted-foreground"
                                >Milestone · {{ stage.clientFacingLabel }}</span
                            >
                        </span>

                        <template v-if="can.update">
                            <AppButton
                                variant="ghost"
                                size="compact"
                                :disabled="index === 0 || undefined"
                                aria-label="Move up"
                                @click="move(index, -1)"
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
                                aria-label="Move down"
                                @click="move(index, 1)"
                            >
                                <ChevronDown
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                size="compact"
                                @click="removeStage(stage)"
                                >Remove</AppButton
                            >
                        </template>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <StatusBadge
                            v-for="gate in stage.gates"
                            :key="gate.id"
                            :tone="gate.isBlocking ? 'warning' : 'neutral'"
                            :label="`${gate.label} · ${gateTypeLabels[gate.gateType] ?? gate.gateType}`"
                            dotless
                        />
                        <AppButton
                            v-for="gate in can.update ? stage.gates : []"
                            :key="`remove-${gate.id}`"
                            variant="ghost"
                            size="compact"
                            :aria-label="`Remove ${gate.label}`"
                            @click="removeGate(stage, gate)"
                            >×</AppButton
                        >
                        <AppButton
                            v-if="can.update"
                            variant="ghost"
                            size="compact"
                            @click="
                                addingTo =
                                    addingTo === stage.id ? null : stage.id
                            "
                            >Add gate</AppButton
                        >
                    </div>

                    <form
                        v-if="addingTo === stage.id"
                        class="flex flex-wrap items-end gap-2"
                        @submit.prevent="addGate(stage)"
                    >
                        <!--
                            The type decides which evaluator answers the gate,
                            so it is a choice rather than a hidden default:
                            every gate added before this picker existed was a
                            manual confirmation whether or not that is what
                            somebody meant.
                        -->
                        <AppSelect
                            v-model="gateForm.gate_type"
                            :options="gateTypes"
                            size="filter"
                            class="w-48"
                        />
                        <AppInput
                            v-model="gateForm.label"
                            size="filter"
                            placeholder="Survey received"
                            class="w-56"
                        />
                        <AppButton size="compact" @click="addGate(stage)"
                            >Add</AppButton
                        >
                    </form>

                    <ul
                        v-if="stage.tasks.length > 0"
                        class="flex flex-col gap-1"
                    >
                        <li
                            v-for="task in stage.tasks"
                            :key="task.id"
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                task.title
                            }}</span>
                            <span v-if="task.isRequired">Required</span>
                            <AppButton
                                v-if="can.update"
                                variant="ghost"
                                size="compact"
                                :aria-label="`Remove ${task.title}`"
                                @click="removeTask(stage, task)"
                                >×</AppButton
                            >
                        </li>
                    </ul>

                    <AppButton
                        v-if="can.update && addingTaskTo !== stage.id"
                        variant="ghost"
                        size="compact"
                        @click="addingTaskTo = stage.id"
                        >Add task</AppButton
                    >

                    <form
                        v-if="can.update && addingTaskTo === stage.id"
                        class="flex flex-wrap items-end gap-2"
                        @submit.prevent="addTask(stage)"
                    >
                        <AppInput
                            v-model="taskForm.title"
                            size="filter"
                            placeholder="Order the survey"
                            class="w-64"
                        />
                        <AppButton size="compact" @click="addTask(stage)"
                            >Add</AppButton
                        >
                        <AppButton
                            variant="ghost"
                            size="compact"
                            @click="
                                addingTaskTo = null;
                                taskForm.reset();
                            "
                            >Cancel</AppButton
                        >
                    </form>
                </li>
            </ul>

            <form
                v-if="can.update"
                class="flex flex-wrap items-end gap-2 border-t px-4 py-3"
                @submit.prevent="addStage"
            >
                <AppInput
                    v-model="stageForm.name"
                    size="filter"
                    placeholder="Under Contract"
                    class="w-64"
                />
                <AppButton size="compact" @click="addStage"
                    >Add stage</AppButton
                >
            </form>
        </Card>
    </div>
</template>
