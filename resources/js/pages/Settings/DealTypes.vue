<script setup lang="ts">
/**
 * S76 — deal types.
 *
 * Three key states, and the third is the one that carries a rule.
 *
 * **Defaults** are the three system types every team gets. They have no
 * controls at all rather than disabled ones (IA §5.1): they belong to nobody,
 * and one team renaming "Rental Placement" for every team on the platform is
 * not what they asked for.
 *
 * **Custom** are the team's own, editable in place.
 *
 * **The in-use warning** is the pattern for every lookup screen in this
 * product. A type live deals point at cannot be deleted without orphaning
 * them, so the only offer is to archive — and the count is shown *before* the
 * choice rather than reported after it. Archiving keeps existing deals
 * labelled and takes the type out of every picker, which is what somebody
 * actually means when they try to delete one.
 */
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, Plus, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import Heading from '@/components/app/Heading.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';

type DealType = {
    id: string;
    name: string;
    side: string;
    sideLabel: string;
    isSystem: boolean;
    archivedAt: string | null;
    /** Null for a system type: a team does not archive one, so it is not asked. */
    dealCount: number | null;
    /*
     * Two facts, not three. Editing and archiving open under exactly the same
     * condition — `DealType::isManageableByTeam()`, which the policy reads
     * too — so sending them separately would imply a distinction that does not
     * exist.
     */
    canManage: boolean;
    canRestore: boolean;
};

defineProps<{
    dealTypes: DealType[];
    /** `value => label`, the shape every other screen takes an enum in. */
    sides: Record<string, string>;
}>();

const page = usePage();

/*
 * The one refusal that has no field to land on.
 *
 * Restoring an archived type fails when its name has since been taken —
 * archiving frees the name, which is the point, so the collision is a real
 * sequence rather than a corner. It comes back from a `router.post` with no
 * form behind it, so it is read off the page's shared errors and given its own
 * alert. Routing it to `restore.errors` would have put it somewhere nothing
 * renders, which is a silent dead end rather than a message.
 */
const restoreError = computed(
    () => (page.props.errors as Record<string, string>)?.restore ?? null,
);

const create = useForm({ name: '', side: '' });

/** The row being edited, or null. One at a time, so a half-typed rename cannot be left behind on another row. */
const editingId = ref<string | null>(null);

/*
 * The row with an archive or restore in flight, so its buttons can be
 * disabled. Without it a double-click sends the request twice, and the second
 * one is refused — restoring an already-restored type is a 403 — which
 * surfaces as an error modal rather than as nothing at all.
 *
 * Archive is incidentally protected by its `window.confirm`, which blocks the
 * second click until the first is answered. Restore has no dialog and is the
 * one that actually needed this.
 */
const busyId = ref<string | null>(null);

const edit = useForm({ name: '', side: '' });

function startEditing(type: DealType): void {
    editingId.value = type.id;
    edit.defaults({ name: type.name, side: type.side });
    edit.reset();
    edit.clearErrors();
}

function cancelEditing(): void {
    editingId.value = null;
    edit.clearErrors();
}

function submitCreate(): void {
    create.post('/settings/deal-types', {
        preserveScroll: true,
        onSuccess: () => create.reset(),
    });
}

function submitEdit(id: string): void {
    edit.patch(`/settings/deal-types/${id}`, {
        preserveScroll: true,
        onSuccess: () => cancelEditing(),
    });
}

/*
 * The warning is the whole point of this screen, so it names the number and
 * says what survives. "Are you sure?" would tell somebody nothing they did not
 * already know; "4 deals keep this type and no new deal can use it" is a
 * decision they can actually make.
 */
function archive(type: DealType): void {
    const count = type.dealCount ?? 0;
    const inUse =
        count > 0
            ? `${count} ${count === 1 ? 'deal keeps' : 'deals keep'} this type and stay exactly as they are — but no new deal will be able to use it. `
            : 'Nothing is using it right now. ';

    if (
        !window.confirm(
            `Archive “${type.name}”? ${inUse}You can restore it at any time.`,
        )
    ) {
        return;
    }

    busyId.value = type.id;

    router.post(
        `/settings/deal-types/${type.id}/archive`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busyId.value = null;
            },
        },
    );
}

function restore(type: DealType): void {
    busyId.value = type.id;

    router.post(
        `/settings/deal-types/${type.id}/restore`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busyId.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Deal types" />

    <div class="flex flex-col gap-4">
        <Heading
            title="Deal types"
            description="What kind of transaction a deal is. This decides which workflows are offered when one is opened."
        />

        <Alert v-if="restoreError" variant="destructive">
            <AlertDescription>{{ restoreError }}</AlertDescription>
        </Alert>

        <Card title="Add a deal type">
            <form
                class="flex flex-col gap-4 px-4 py-4"
                @submit.prevent="submitCreate"
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="new_name">Name</Label>
                        <AppInput
                            id="new_name"
                            v-model="create.name"
                            required
                            maxlength="120"
                        />
                        <p
                            v-if="create.errors.name"
                            class="text-[11px] text-state-danger"
                        >
                            {{ create.errors.name }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="new_side">Side</Label>
                        <select
                            id="new_side"
                            v-model="create.side"
                            required
                            class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                        >
                            <option value="" disabled>Choose a side</option>
                            <option
                                v-for="(label, value) in sides"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                        <p
                            v-if="create.errors.side"
                            class="text-[11px] text-state-danger"
                        >
                            {{ create.errors.side }}
                        </p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <AppButton type="submit" :disabled="create.processing">
                        <Plus class="size-4" aria-hidden="true" />
                        Add deal type
                    </AppButton>
                </div>
            </form>
        </Card>

        <Card title="Your deal types">
            <EmptyState
                v-if="dealTypes.length === 0"
                title="No deal types yet"
                description="The three defaults should be here. If they are not, the reference data has not been seeded."
            />
            <ul v-else class="flex flex-col">
                <li
                    v-for="type in dealTypes"
                    :key="type.id"
                    class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <form
                        v-if="editingId === type.id"
                        class="flex w-full flex-wrap items-start gap-3"
                        @submit.prevent="submitEdit(type.id)"
                    >
                        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <Label :for="`edit_name_${type.id}`" class="sr-only"
                                >Name</Label
                            >
                            <AppInput
                                :id="`edit_name_${type.id}`"
                                v-model="edit.name"
                                required
                                maxlength="120"
                            />
                            <p
                                v-if="edit.errors.name"
                                class="text-[11px] text-state-danger"
                            >
                                {{ edit.errors.name }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label :for="`edit_side_${type.id}`" class="sr-only"
                                >Side</Label
                            >
                            <select
                                :id="`edit_side_${type.id}`"
                                v-model="edit.side"
                                required
                                class="h-11 rounded-md border bg-background px-3 text-base md:h-10 md:text-sm"
                            >
                                <option
                                    v-for="(label, value) in sides"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                            <p
                                v-if="edit.errors.side"
                                class="text-[11px] text-state-danger"
                            >
                                {{ edit.errors.side }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <AppButton
                                type="submit"
                                size="compact"
                                :disabled="edit.processing"
                                >Save</AppButton
                            >
                            <AppButton
                                variant="ghost"
                                size="compact"
                                @click="cancelEditing"
                                >Cancel</AppButton
                            >
                        </div>
                    </form>

                    <template v-else>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-13 font-medium">{{
                                type.name
                            }}</span>
                            <span
                                v-if="
                                    type.dealCount !== null &&
                                    type.dealCount > 0
                                "
                                class="truncate text-[11px] text-muted-foreground"
                                >{{ type.dealCount }}
                                {{ type.dealCount === 1 ? 'deal' : 'deals' }}
                                using this</span
                            >
                        </span>

                        <StatusBadge
                            tone="neutral"
                            :label="type.sideLabel"
                            dotless
                        />
                        <StatusBadge
                            v-if="type.isSystem"
                            tone="info"
                            label="Default"
                            dotless
                        />
                        <StatusBadge
                            v-if="type.archivedAt"
                            tone="neutral"
                            label="Archived"
                            dotless
                        />

                        <!--
                            A system default gets no controls at all rather
                            than disabled ones (IA §5.1). It belongs to every
                            team, so there is nothing here for this team to
                            change and a greyed-out button would only invite
                            the question.
                        -->
                        <AppButton
                            v-if="type.canManage"
                            variant="ghost"
                            @click="startEditing(type)"
                            >Edit</AppButton
                        >
                        <AppButton
                            v-if="type.canManage"
                            variant="ghost"
                            :disabled="busyId === type.id"
                            @click="archive(type)"
                        >
                            <Archive class="size-4" aria-hidden="true" />
                            Archive
                        </AppButton>
                        <AppButton
                            v-if="type.canRestore"
                            variant="ghost"
                            :disabled="busyId === type.id"
                            @click="restore(type)"
                        >
                            <RotateCcw class="size-4" aria-hidden="true" />
                            Restore
                        </AppButton>
                    </template>
                </li>
            </ul>
        </Card>
    </div>
</template>
