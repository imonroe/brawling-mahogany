<script setup lang="ts">
/**
 * S75 — roles and permissions (PRD §4.2 F2.3 · IA §7 · issue #88).
 *
 * ## Composed, never edited
 *
 * F2.3 is that a team differs by composing a **new** role. The five shipped
 * ones are the product's: a team that edited Team Member would change what
 * that name means for anybody reading their own audit log six months later.
 * System rows therefore get **no controls at all** rather than disabled ones
 * — Frontend conventions §4's rule, and this is its second screen after S76.
 *
 * ## The count comes before the choice
 *
 * A role held by four people is a role whose archiving takes four people's
 * access with it, and that is a sentence somebody should read *before* they
 * press the button rather than in a toast afterwards. There is no delete:
 * archiving is reversible, and a role appears in every membership that ever
 * held it.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { formatCount } from '@/lib/formatters';

type RoleRow = {
    id: string;
    name: string;
    description: string | null;
    isSystem: boolean;
    isArchived: boolean;
    holders: number;
    permissions: string[];
};

const props = defineProps<{
    roles: RoleRow[];
    catalogue: { key: string; group: string; description: string }[];
    can: { manage: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Roles', href: '/settings/roles' },
        ],
    },
});

/*
 * One form for both verbs, holding the role being edited or `null` for a new
 * one. Composing a role you can never adjust is half a feature — the first
 * thing anybody does after building "Transaction Coordinator" is discover it
 * needs one more permission — and a second form would be the same fields
 * twice, drifting the first time the catalogue changes.
 */
const editing = ref<RoleRow | null>(null);
const creating = ref(false);
const open = computed(() => creating.value || editing.value !== null);

const form = useForm<{
    name: string;
    description: string;
    permissions: string[];
}>({ name: '', description: '', permissions: [] });

function startCreating(): void {
    editing.value = null;
    creating.value = !creating.value;
    form.reset();
    form.clearErrors();
}

function startEditing(role: RoleRow): void {
    creating.value = false;
    editing.value = role;
    form.clearErrors();
    form.name = role.name;
    form.description = role.description ?? '';
    form.permissions = [...role.permissions];
}

function cancel(): void {
    creating.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
}

/** The catalogue as it is described — grouped, in the order it arrives. */
const groups = computed(() => {
    const byGroup = new Map<string, typeof props.catalogue>();

    for (const entry of props.catalogue) {
        byGroup.set(entry.group, [...(byGroup.get(entry.group) ?? []), entry]);
    }

    return [...byGroup.entries()].map(([name, entries]) => ({ name, entries }));
});

function toggle(key: string, on: boolean): void {
    form.permissions = on
        ? [...form.permissions, key]
        : form.permissions.filter((one) => one !== key);
}

function submit(): void {
    const role = editing.value;

    const options = {
        preserveScroll: true,
        onSuccess: () => cancel(),
    };

    if (role) {
        form.patch(`/settings/roles/${role.id}`, options);

        return;
    }

    form.post('/settings/roles', options);
}

function archive(role: RoleRow): void {
    /*
     * The count in the question, not in the confirmation afterwards. IA §10:
     * name the object and the consequence.
     */
    const held =
        role.holders > 0
            ? ` ${formatCount(role.holders, 'person', 'people')} currently ${role.holders === 1 ? 'holds' : 'hold'} it, and would lose what it grants.`
            : '';

    if (!window.confirm(`Archive ${role.name}?${held}`)) {
        return;
    }

    router.delete(`/settings/roles/${role.id}/archive`, {
        preserveScroll: true,
    });
}

function restore(role: RoleRow): void {
    router.post(
        `/settings/roles/${role.id}/restore`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Roles" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Roles"
            subtitle="What each role may do. The shipped five are the product’s — compose your own beside them."
        >
            <template v-if="can.manage" #actions>
                <AppButton @click="startCreating">{{
                    open ? 'Cancel' : 'New role'
                }}</AppButton>
            </template>
        </PageHeader>

        <Card
            v-if="open"
            :title="editing ? `Editing ${editing.name}` : 'New role'"
        >
            <form
                class="flex flex-col gap-4 px-4 py-4"
                @submit.prevent="submit"
            >
                <AppInput
                    v-model="form.name"
                    placeholder="Transaction Coordinator"
                />
                <p v-if="form.errors.name" class="text-xs text-destructive">
                    {{ form.errors.name }}
                </p>

                <div
                    v-for="group in groups"
                    :key="group.name"
                    class="flex flex-col gap-2"
                >
                    <h3
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        {{ group.name }}
                    </h3>
                    <label
                        v-for="entry in group.entries"
                        :key="entry.key"
                        class="flex cursor-pointer items-start gap-2.5"
                    >
                        <Checkbox
                            :model-value="form.permissions.includes(entry.key)"
                            class="mt-0.5"
                            @update:model-value="
                                (value) => toggle(entry.key, value === true)
                            "
                        />
                        <span class="text-13 text-foreground">{{
                            entry.description
                        }}</span>
                    </label>
                </div>

                <div class="flex gap-2">
                    <AppButton :disabled="form.processing" @click="submit">{{
                        editing ? 'Save role' : 'Create role'
                    }}</AppButton>
                    <AppButton variant="ghost" @click="cancel"
                        >Cancel</AppButton
                    >
                </div>
            </form>
        </Card>

        <Card title="Roles">
            <ul class="flex flex-col">
                <li
                    v-for="role in roles"
                    :key="role.id"
                    class="flex items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-13 font-medium">{{
                            role.name
                        }}</span>
                        <span
                            class="truncate text-[11px] text-muted-foreground"
                        >
                            {{ formatCount(role.holders, 'person', 'people') }}
                            ·
                            {{
                                formatCount(
                                    role.permissions.length,
                                    'permission',
                                )
                            }}
                        </span>
                    </span>

                    <StatusBadge
                        v-if="role.isSystem"
                        tone="neutral"
                        label="Built in"
                        dotless
                    />
                    <StatusBadge
                        v-if="role.isArchived"
                        tone="neutral"
                        label="Archived"
                        dotless
                    />

                    <!--
                        Nothing at all on a system row, rather than a disabled
                        button advertising a capability that does not exist
                        here (§7.3, Frontend conventions §4).
                    -->
                    <template v-if="can.manage && !role.isSystem">
                        <AppButton
                            v-if="role.isArchived"
                            variant="ghost"
                            size="compact"
                            @click="restore(role)"
                            >Restore</AppButton
                        >
                        <template v-else>
                            <AppButton
                                variant="ghost"
                                size="compact"
                                @click="startEditing(role)"
                                >Edit</AppButton
                            >
                            <AppButton
                                variant="ghost"
                                size="compact"
                                @click="archive(role)"
                                >Archive</AppButton
                            >
                        </template>
                    </template>
                </li>
            </ul>
        </Card>
    </div>
</template>
