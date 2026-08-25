<script setup lang="ts">
/**
 * S39, S40 — the templates index and the pack browser (PRD F4.1 · #84).
 *
 * **Two lists, because they are two different things.** A team's own
 * templates are editable; a pack's are not — one pack is shared by every
 * team, so `WorkflowTemplatePolicy` refuses to update a system row. Showing
 * both in one list with some rows quietly read-only is the confusion this
 * separation avoids, and the pack browser's only verb is *Use a copy*.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { formatCount } from '@/lib/formatters';

type TemplateRow = {
    id: string;
    name: string;
    description: string | null;
    isActive: boolean;
    isSystem: boolean;
    stageCount: number;
    url: string;
};

defineProps<{
    templates: TemplateRow[];
    packs: {
        id: string;
        name: string;
        description: string | null;
        templates: TemplateRow[];
    }[];
    can: { manage: boolean };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Templates', href: '/templates' }] },
});

const creating = ref(false);
const form = useForm({ name: '', description: '' });

function create(): void {
    form.post('/templates', { onSuccess: () => form.reset() });
}

function copy(template: TemplateRow): void {
    router.post(`/templates/${template.id}/copy`);
}
</script>

<template>
    <Head title="Templates" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Templates"
            subtitle="What your team intends to happen, before a deal makes it real"
        >
            <template #actions>
                <!--
                    S45 lives under Templates rather than in the sidebar: a
                    message template is a template, and IA §5.1 does not grow a
                    second nav item for the second kind of one.
                -->
                <AppButton variant="ghost" href="/templates/messages"
                    >Message templates</AppButton
                >
                <AppButton v-if="can.manage" @click="creating = !creating">{{
                    creating ? 'Cancel' : 'New template'
                }}</AppButton>
            </template>
        </PageHeader>

        <Card v-if="creating" title="New workflow template">
            <form
                class="flex flex-col gap-3 px-4 py-4"
                @submit.prevent="create"
            >
                <AppInput v-model="form.name" placeholder="Listing to Close" />
                <p v-if="form.errors.name" class="text-xs text-destructive">
                    {{ form.errors.name }}
                </p>
                <div class="flex">
                    <AppButton :disabled="form.processing" @click="create"
                        >Create</AppButton
                    >
                </div>
            </form>
        </Card>

        <Card title="Your templates">
            <EmptyState
                v-if="templates.length === 0"
                title="You have not made one yet"
                description="Start from scratch, or take a copy of a pack below and change it to suit."
            />
            <ul v-else class="flex flex-col">
                <li
                    v-for="template in templates"
                    :key="template.id"
                    class="flex items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <TextLink
                            :href="template.url"
                            class="truncate text-13 font-medium"
                            >{{ template.name }}</TextLink
                        >
                        <span
                            class="truncate text-[11px] text-muted-foreground"
                            >{{
                                formatCount(template.stageCount, 'stage')
                            }}</span
                        >
                    </span>
                    <StatusBadge
                        v-if="!template.isActive"
                        tone="neutral"
                        label="Inactive"
                        dotless
                    />
                </li>
            </ul>
        </Card>

        <!--
            The pack browser. Read-only by construction — these rows belong to
            every team, and the only verb is taking a copy.
        -->
        <Card v-for="pack in packs" :key="pack.id" :title="pack.name">
            <p
                v-if="pack.description"
                class="border-b px-4 py-2.5 text-xs text-muted-foreground"
            >
                {{ pack.description }}
            </p>
            <ul class="flex flex-col">
                <li
                    v-for="template in pack.templates"
                    :key="template.id"
                    class="flex items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <TextLink
                            :href="template.url"
                            class="truncate text-13 font-medium"
                            >{{ template.name }}</TextLink
                        >
                        <span
                            class="truncate text-[11px] text-muted-foreground"
                            >{{
                                formatCount(template.stageCount, 'stage')
                            }}</span
                        >
                    </span>
                    <AppButton
                        v-if="can.manage"
                        variant="ghost"
                        size="compact"
                        @click="copy(template)"
                        >Use a copy</AppButton
                    >
                </li>
            </ul>
        </Card>
    </div>
</template>
