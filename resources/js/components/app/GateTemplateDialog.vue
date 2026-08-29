<script setup lang="ts">
/**
 * S43 — the gate editor (PRD F4.1 · issues #86, #87, #109).
 *
 * ## "Gate", not "Requirement"
 *
 * IA §11 allows the softer word only in the deal view, where a person is
 * looking at their own transaction. This is the template editor, so the
 * strings here say Gate — the same call `StageTemplateController::updateGate()`
 * makes in its own toast.
 *
 * ## Editing rather than remove-and-re-add
 *
 * #11's markup pass over a pack file is a hundred small corrections, and a
 * gate deleted to change one word loses its place in the order, which a
 * re-add puts at the end. `updateGate()` validates with the same rules `addGate`
 * does, deliberately: the type may change, and with it whether a key date is
 * required.
 *
 * ## What may be picked is narrower than what exists
 *
 * `gateTypes` comes from `GateRegistry::selectableOptions()` — the types this
 * editor can fully specify. Five of the seven evaluators read a configuration
 * there are no fields for, and a gate composed without one is a stage only an
 * **override** can pass, built in two clicks. A pack file may carry the wider
 * list; this picker may not.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export type GateTemplateValues = {
    id: string;
    gateType: string;
    label: string;
    isBlocking: boolean;
    config: Record<string, unknown>;
};

const props = defineProps<{
    open: boolean;
    templateId: string;
    stageTemplateId: string;
    /** The gate being edited, or null when this is an add. */
    gate: GateTemplateValues | null;
    /** What may be picked — `GateRegistry::selectableOptions()`. */
    gateTypes: Record<string, string>;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm<{
    gate_type: string;
    label: string;
    is_blocking: boolean;
    config: {
        /*
         * Present and a string rather than optional. An optional key types as
         * `string | undefined` — which is the same value at runtime and a
         * different one to the compiler, and `AppInput` binds a string.
         */
        keyDateName: string;
    };
}>({
    gate_type: '',
    label: '',
    /*
     * True, which is `gate_templates.is_blocking`'s own column default: a gate
     * exists to stop something, and an advisory one is the exception.
     */
    is_blocking: true,
    config: { keyDateName: '' },
});

/*
 * Filled on open rather than on mount. One dialog is mounted for the screen
 * and reused for every row, so reopening it on a second gate must not show the
 * first one's answers — and a stale `gate_type` is the worst of them, because
 * it decides which evaluator ever answers this gate.
 */
watch(
    () => [props.open, props.gate?.id] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        /*
         * The first key the registry offers, rather than a literal.
         * `EVALUATORS` is ordered with the ones that work today first, so the
         * default is one that can actually clear — and a reordering there
         * changes this without anybody having to remember the dialog exists.
         */
        form.gate_type =
            props.gate?.gateType ?? Object.keys(props.gateTypes)[0] ?? '';
        form.label = props.gate?.label ?? '';
        form.is_blocking = props.gate?.isBlocking ?? true;

        /*
         * Read out of the stored config by key and type-checked, never spread
         * behind a cast: a `manual_confirmation` gate stores nothing, so a
         * spread leaves `keyDateName` `undefined` — an unbound control at
         * runtime and a different type to the compiler.
         */
        const stored = props.gate?.config ?? {};

        form.config = {
            keyDateName:
                typeof stored.keyDateName === 'string'
                    ? stored.keyDateName
                    : '',
        };
    },
    { immediate: true },
);

/**
 * Whether the chosen type needs a date named before it can be saved.
 *
 * The server asks the same question through `GateRegistry::needsKeyDate()`,
 * and refuses a `date_reached` gate with no date — `required_if` rather than
 * `required_with`, so omitting the field is refused rather than saving a gate
 * only an override could pass.
 */
const needsKeyDate = computed(() => form.gate_type === 'date_reached');

/*
 * A choice that stops being offered must stop being held. Switching from
 * "Date reached" to "Manual confirmation" would otherwise leave a key date
 * name on a type no evaluator reads it with — and `updateGate()` clears the
 * whole `config` column before filling, so what the screen shows and what the
 * row keeps would disagree.
 */
watch(needsKeyDate, (needed) => {
    if (!needed) {
        form.config = { ...form.config, keyDateName: '' };
    }
});

const base = computed(
    () =>
        `/templates/${props.templateId}/stages/${props.stageTemplateId}/gates`,
);

function submit(): void {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.gate) {
        form.patch(`${base.value}/${props.gate.id}`, options);

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
                <DialogTitle>{{ gate ? 'Edit gate' : 'Add gate' }}</DialogTitle>
                <DialogDescription>
                    Something that has to be true before the deal can advance
                    past this stage. Deals already running keep the gates they
                    started with.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label for="gate_template_type">Type</Label>
                    <!--
                        The type decides which evaluator answers the gate, so
                        it is a choice rather than a hidden default: every gate
                        added before this picker existed was a manual
                        confirmation whether or not that is what somebody
                        meant.
                    -->
                    <AppSelect
                        id="gate_template_type"
                        v-model="form.gate_type"
                        :options="gateTypes"
                        size="default"
                    />
                    <p class="text-[11px] text-muted-foreground">
                        What has to be true, and so what clears it.
                    </p>
                    <p
                        v-if="form.errors.gate_type"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.gate_type }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="gate_template_label">Label</Label>
                    <AppInput
                        id="gate_template_label"
                        v-model="form.label"
                        maxlength="120"
                        placeholder="Survey received"
                    />
                    <p class="text-[11px] text-muted-foreground">
                        What the team sees on the deal when this gate is holding
                        things up.
                    </p>
                    <p
                        v-if="form.errors.label"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.label }}
                    </p>
                </div>

                <!--
                    Only for the one type that needs it. A field that is always
                    on screen and usually meaningless is a field people fill in
                    anyway — and the server drops the key outright for every
                    other type, so a value held here could never be saved.
                -->
                <div v-if="needsKeyDate" class="flex flex-col gap-1.5">
                    <Label for="gate_template_key_date">Which date</Label>
                    <AppInput
                        id="gate_template_key_date"
                        v-model="form.config.keyDateName"
                        maxlength="120"
                        placeholder="Inspection objection"
                    />
                    <!--
                        Free text, and it has to be: a gate lives on a
                        template, and a template has never met the deal it will
                        run on, so there is no key date row to point at. The
                        two sides meet on the word the team uses, folded for
                        case and whitespace by the evaluator.
                    -->
                    <p class="text-[11px] text-muted-foreground">
                        Name the date this waits for — the same name the deal
                        uses for it on Dates &amp; Deadlines. Capitals and
                        spacing do not matter.
                    </p>
                    <p
                        v-if="form.errors['config.keyDateName']"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors['config.keyDateName'] }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="flex items-center gap-2 text-13">
                        <input v-model="form.is_blocking" type="checkbox" />
                        Blocking
                    </label>
                    <!--
                        Design System §12: a consequential input carries its
                        consequence beneath it, and the consequence has to be
                        true. Getting past a blocking gate takes an Override,
                        which is audited — Override and Skip are different
                        actions (IA §7) and neither word is used loosely here.
                    -->
                    <p class="text-[11px] text-muted-foreground">
                        A blocking gate stops the stage advancing until it
                        clears. On a running deal, moving on without clearing it
                        means an override with a reason, or skipping the stage —
                        both are recorded on the deal. Untick this to show the
                        gate without holding anything up.
                    </p>
                    <p
                        v-if="form.errors.is_blocking"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.is_blocking }}
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
                        gate ? 'Save gate' : 'Add gate'
                    }}</AppButton>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
