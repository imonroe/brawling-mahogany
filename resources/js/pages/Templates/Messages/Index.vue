<script setup lang="ts">
/**
 * S45 — the message templates list (PRD F5.5 · issue #90).
 *
 * ## Three states, and the third is the one that carries the rule
 *
 * **Empty**, **unused**, and **in use by N automations** — and the count is
 * what stops somebody removing the template three live automations depend on.
 * Shown *before* the choice rather than reported after it, which is the rule
 * every lookup screen in this product follows (Frontend conventions §4).
 *
 * There is **no delete**. A template is archived, and the automations already
 * standing on it keep it. Archiving is reversible; deleting is what is not.
 *
 * ## Creating happens here and refining happens in the editor
 *
 * The form asks for the least that makes a sendable template — a name, a
 * channel, who it goes to, and words. The HTML body, the merge fields and the
 * preview are S46's, which is where somebody spends the next twenty minutes.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { Archive, Plus, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import AppTextarea from '@/components/app/AppTextarea.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { Label } from '@/components/ui/label';
import { formatCount } from '@/lib/formatters';

type TemplateRow = {
    id: string;
    name: string;
    channel: string;
    channelLabel: string;
    recipient: string;
    archivedAt: string | null;
    inUse: number;
    url: string;
};

const props = defineProps<{
    templates: TemplateRow[];
    /** `value => label`, the shape every screen takes an enum in. */
    channels: Record<string, string>;
    recipientRules: Record<string, string>;
    participantRoles: Record<string, string>;
    can: { manage: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Templates', href: '/templates' }],
    },
});

const creating = ref(false);
const busyId = ref<string | null>(null);

const form = useForm<{
    name: string;
    channel: string;
    subject: string;
    body_text: string;
    recipient_rule: { type: string; participantRole: string | null };
}>({
    name: '',
    channel: Object.keys(props.channels)[0] ?? 'email',
    subject: '',
    body_text: '',
    recipient_rule: {
        type: Object.keys(props.recipientRules)[0] ?? 'primary_contact',
        participantRole: null,
    },
});

/*
 * A push notification has no subject line, so the field is not merely
 * optional — the server *prohibits* it. Hiding it is the same rule the
 * validator holds, said in the interface, which is what "a progressive form
 * that narrows" means one screen over.
 */
const hasSubject = computed(() => form.channel === 'email');

const needsParticipantRole = computed(
    () => form.recipient_rule.type === 'participant_role',
);

function submit(): void {
    if (!hasSubject.value) {
        form.subject = '';
    }

    form.transform((data) => ({
        ...data,
        subject: hasSubject.value ? data.subject : undefined,
        /*
         * Both keys, always. The role is dropped server-side by
         * `Rule::excludeIf` when the rule does not take one, so sending it as
         * null is the same request — and a payload whose *shape* changes with
         * a checkbox is a payload the compiler cannot type.
         */
        recipient_rule: {
            type: data.recipient_rule.type,
            participantRole: needsParticipantRole.value
                ? data.recipient_rule.participantRole
                : null,
        },
    })).post('/templates/messages');
}

function releaseRow(): void {
    busyId.value = null;
}

function archive(template: TemplateRow): void {
    /*
     * The number in the question, and what survives it. "Are you sure?" tells
     * somebody nothing they did not already know; "3 automations keep this and
     * no new one can choose it" is a decision they can make.
     */
    const inUse =
        template.inUse > 0
            ? `${formatCount(template.inUse, 'automation')} keep it and go on sending it — but no new automation will be able to choose it. `
            : 'Nothing is using it right now. ';

    if (
        !window.confirm(
            `Archive “${template.name}”? ${inUse}You can restore it at any time.`,
        )
    ) {
        return;
    }

    busyId.value = template.id;

    router.post(
        `/templates/messages/${template.id}/archive`,
        {},
        { preserveScroll: true, onFinish: releaseRow, onCancel: releaseRow },
    );
}

function restore(template: TemplateRow): void {
    busyId.value = template.id;

    router.post(
        `/templates/messages/${template.id}/restore`,
        {},
        { preserveScroll: true, onFinish: releaseRow, onCancel: releaseRow },
    );
}
</script>

<template>
    <Head title="Message templates" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Message templates"
            subtitle="The words your automations send, and the rule for who gets them."
        >
            <template v-if="can.manage" #actions>
                <AppButton @click="creating = !creating">
                    <Plus v-if="!creating" class="size-4" aria-hidden="true" />
                    {{ creating ? 'Cancel' : 'New template' }}
                </AppButton>
            </template>
        </PageHeader>

        <Card v-if="creating" title="New message template">
            <form
                class="flex flex-col gap-4 px-4 py-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="new_name">Name</Label>
                        <AppInput
                            id="new_name"
                            v-model="form.name"
                            size="default"
                            maxlength="120"
                            placeholder="Inspection scheduled"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="new_channel">How it is sent</Label>
                        <AppSelect
                            id="new_channel"
                            v-model="form.channel"
                            :options="channels"
                            size="default"
                        />
                        <p
                            v-if="form.errors.channel"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.channel }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="new_recipient">Who it goes to</Label>
                        <AppSelect
                            id="new_recipient"
                            v-model="form.recipient_rule.type"
                            :options="recipientRules"
                            size="default"
                        />
                        <p
                            v-if="form.errors['recipient_rule.type']"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors['recipient_rule.type'] }}
                        </p>
                    </div>

                    <div
                        v-if="needsParticipantRole"
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="new_role">Which role</Label>
                        <AppSelect
                            id="new_role"
                            v-model="form.recipient_rule.participantRole"
                            :options="participantRoles"
                            size="default"
                            placeholder="Choose a role"
                        />
                        <p
                            v-if="form.errors['recipient_rule.participantRole']"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors['recipient_rule.participantRole'] }}
                        </p>
                    </div>
                </div>

                <div v-if="hasSubject" class="flex flex-col gap-1.5">
                    <Label for="new_subject">Subject</Label>
                    <AppInput
                        id="new_subject"
                        v-model="form.subject"
                        size="default"
                        maxlength="200"
                    />
                    <p
                        v-if="form.errors.subject"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.subject }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="new_body">Message</Label>
                    <AppTextarea id="new_body" v-model="form.body_text" />
                    <p class="text-[11px] text-muted-foreground">
                        Plain text for now. You can add merge fields, a
                        formatted version and a preview once it is saved.
                    </p>
                    <p
                        v-if="form.errors.body_text"
                        class="text-[11px] text-state-danger"
                    >
                        {{ form.errors.body_text }}
                    </p>
                </div>

                <div class="flex justify-end">
                    <AppButton type="submit" :disabled="form.processing">
                        Create template
                    </AppButton>
                </div>
            </form>
        </Card>

        <Card title="Your templates">
            <EmptyState
                v-if="templates.length === 0"
                title="No message templates yet"
                description="Write one, then point an automation at it so a milestone tells the client itself."
            />
            <ul v-else class="flex flex-col">
                <li
                    v-for="template in templates"
                    :key="template.id"
                    class="flex min-h-11 flex-wrap items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <TextLink
                            :href="template.url"
                            class="truncate text-13 font-medium"
                            >{{ template.name }}</TextLink
                        >
                        <span class="truncate text-[11px] text-muted-foreground"
                            >Goes to {{ template.recipient }}</span
                        >
                    </span>

                    <StatusBadge
                        tone="neutral"
                        :label="template.channelLabel"
                        dotless
                    />
                    <!--
                        The in-use count, before the choice. Absent rather than
                        showing a zero: "0 automations" reads as a warning about
                        nothing.
                    -->
                    <StatusBadge
                        v-if="template.inUse > 0"
                        tone="info"
                        :label="`In use by ${formatCount(template.inUse, 'automation')}`"
                        dotless
                    />
                    <StatusBadge
                        v-if="template.archivedAt"
                        tone="neutral"
                        label="Archived"
                        dotless
                    />

                    <template v-if="can.manage">
                        <AppButton
                            v-if="!template.archivedAt"
                            variant="ghost"
                            :disabled="busyId === template.id"
                            @click="archive(template)"
                        >
                            <Archive class="size-4" aria-hidden="true" />
                            Archive
                        </AppButton>
                        <AppButton
                            v-else
                            variant="ghost"
                            :disabled="busyId === template.id"
                            @click="restore(template)"
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
