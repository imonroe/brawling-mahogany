<script setup lang="ts">
/**
 * S42 — the stage template editor (PRD F4.1 · issues #86, #87, #11).
 *
 * ## Why a dialog rather than the inline row S42 shipped with
 *
 * The screen could add a stage and rename nothing. #87's blocker is #11's
 * content, and #11's content is not a list of stage names — it is the
 * *metadata* around them: who owns each stage, how long it usually takes, and
 * the sentence a client is told when it completes. Until a screen can take
 * those, a markup pass over a pack file is ninety delete-and-re-add cycles,
 * each of which loses the stage's place in the order. That is why this exists.
 *
 * ## Editing this cannot reach a deal already running
 *
 * PRD §8.1's template/instance split: `InstantiateWorkflow` snapshots at the
 * moment a workflow starts, so nothing typed here touches the runtime layer.
 * The dialog says so, because a team that believes editing a template fixes a
 * live deal will edit a template instead of fixing the deal.
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
import { fromNullableInteger, toNullableInteger } from '@/lib/numbers';

export type StageTemplateValues = {
    id: string;
    name: string;
    description: string | null;
    expectedDurationDays: number | null;
    ownerRole: string | null;
    isMilestone: boolean;
    clientFacingLabel: string | null;
};

const props = defineProps<{
    open: boolean;
    templateId: string;
    /** The stage being edited, or null when this is an add. */
    stage: StageTemplateValues | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

/*
 * `expected_duration_days` is held as a **string**, not a number.
 *
 * It is `nullable|integer|between:0,365` on the server, and this is a text
 * input, so the value in hand between two keystrokes is a string — including
 * the empty one, which is *no answer* rather than zero. `submit()` converts;
 * see `lib/numbers`, which both dialogs import rather than each keeping a
 * copy — `reorder.ts`'s argument, in this same change.
 *
 * The three nullable strings are held as `''` for the same reason: a text
 * input has no way to say null, and `ConvertEmptyStringsToNull` (Laravel's
 * global middleware, still on — `bootstrap/app.php` removes it from nothing)
 * turns `''` back into null on the way in, which is what `nullable|string`
 * wants.
 */
const form = useForm<{
    name: string;
    description: string;
    expected_duration_days: string;
    owner_role: string;
    is_milestone: boolean;
    client_facing_label: string;
}>({
    name: '',
    description: '',
    expected_duration_days: '',
    owner_role: '',
    is_milestone: false,
    client_facing_label: '',
});

/*
 * Filled on open rather than on mount. The dialog is mounted once for the
 * screen and reused for every row, so reopening it on a second stage must not
 * show the first one's answers — and a stale `client_facing_label` is the
 * worst of them, because it is a sentence a client reads.
 */
watch(
    () => [props.open, props.stage?.id] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.name = props.stage?.name ?? '';
        form.description = props.stage?.description ?? '';
        form.expected_duration_days = fromNullableInteger(
            props.stage?.expectedDurationDays,
        );
        form.owner_role = props.stage?.ownerRole ?? '';
        form.is_milestone = props.stage?.isMilestone ?? false;
        form.client_facing_label = props.stage?.clientFacingLabel ?? '';
    },
    { immediate: true },
);

const base = computed(() => `/templates/${props.templateId}/stages`);

function submit(): void {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('update:open', false),
    };

    form.transform((data) => ({
        ...data,
        expected_duration_days: toNullableInteger(data.expected_duration_days),
    }));

    if (props.stage) {
        form.patch(`${base.value}/${props.stage.id}`, options);

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
                <DialogTitle>{{
                    stage ? 'Edit stage' : 'Add stage'
                }}</DialogTitle>
                <DialogDescription>
                    A period of the deal — it holds the tasks to do and the
                    gates that must clear before it can advance. Deals already
                    running keep the stages they started with.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1.5">
                    <Label for="stage_template_name">Name</Label>
                    <AppInput
                        id="stage_template_name"
                        v-model="form.name"
                        maxlength="120"
                        placeholder="Under contract"
                    />
                    <p class="text-[11px] text-muted-foreground">
                        What the team calls it. A client never reads this one.
                    </p>
                    <p
                        v-if="form.errors.name"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="stage_template_description">Description</Label>
                    <AppTextarea
                        id="stage_template_description"
                        v-model="form.description"
                        :rows="3"
                        maxlength="2000"
                    />
                    <p class="text-[11px] text-muted-foreground">
                        What this period of the deal is for. Optional.
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
                        <Label for="stage_template_duration"
                            >How long it usually takes</Label
                        >
                        <AppInput
                            id="stage_template_duration"
                            v-model="form.expected_duration_days"
                            inputmode="numeric"
                            placeholder="14"
                        />
                        <p class="text-[11px] text-muted-foreground">
                            In days. Leave it empty when it varies too much to
                            say.
                        </p>
                        <p
                            v-if="form.errors.expected_duration_days"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.expected_duration_days }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="stage_template_owner">Who owns it</Label>
                        <AppInput
                            id="stage_template_owner"
                            v-model="form.owner_role"
                            maxlength="120"
                            placeholder="Transaction coordinator"
                        />
                        <!--
                            #64: a role and never a person. A template ships
                            between teams, so the two sides meet on the word
                            rather than on a foreign key.
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
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="flex items-center gap-2 text-13">
                        <input v-model="form.is_milestone" type="checkbox" />
                        A milestone — worth telling the client about
                    </label>
                    <!--
                        Design System §12: a consequential input carries its
                        consequence beneath it. This one decides whether a
                        client hears about the stage at all.
                    -->
                    <p class="text-[11px] text-muted-foreground">
                        IA §3: a milestone is a moment, not a period — the
                        notable completion of this stage.
                    </p>
                    <p
                        v-if="form.errors.is_milestone"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.is_milestone }}
                    </p>
                </div>

                <!--
                    Only for a milestone, because it means nothing otherwise:
                    `Stage::clientAnnouncement()` returns null for a stage
                    that is not one, whatever the label says.

                    Deliberately **not** cleared when the checkbox goes off,
                    unlike the key-date pair in `GateTemplateDialog`. That rule
                    exists so the server cannot refuse a value on a control the
                    reader can no longer see, and `client_facing_label` is
                    `nullable` for every stage — so nothing can be refused, and
                    clearing it would silently destroy a sentence somebody
                    wrote because they tapped a checkbox twice.
                -->
                <div v-if="form.is_milestone" class="flex flex-col gap-1.5">
                    <Label for="stage_template_client_label"
                        >What the client is told</Label
                    >
                    <AppInput
                        id="stage_template_client_label"
                        v-model="form.client_facing_label"
                        maxlength="160"
                        placeholder="Your inspection is complete"
                    />
                    <p class="text-[11px] text-muted-foreground">
                        The sentence a client reads on their status page when
                        this stage completes. A stage with no label is left off
                        the client's page entirely rather than renamed —
                        internal stage names are accurate, useful, and not for
                        sharing.
                    </p>
                    <p
                        v-if="form.errors.client_facing_label"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.client_facing_label }}
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
                        stage ? 'Save stage' : 'Add stage'
                    }}</AppButton>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
