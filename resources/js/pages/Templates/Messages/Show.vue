<script setup lang="ts">
/**
 * S46 — the message template editor (PRD F5.5, F5.6 · issue #90).
 *
 * ## The preview renders a real deal, and it renders the *draft*
 *
 * Issue #90: *"Live preview renders against real merge data from a chosen
 * deal, not lorem ipsum. The whole point is seeing what the client will
 * actually receive."* So Preview posts what is in the form — not the saved row
 * — and the server renders it through the same `RenderMessage` the send path
 * uses. A preview produced by a second renderer would be a plausible guess.
 *
 * ## The HTML preview is an iframe, and that is a security decision
 *
 * A template body is markup somebody with `templates.manage` wrote, and it is
 * their own outbound email — but this screen renders it back **inside a
 * colleague's session**. `v-html` would make a stored script tag run there, so
 * the body goes into a `sandbox`ed iframe with no allowances at all: no
 * scripts, no forms, no same-origin. Merge values are escaped on the server
 * as well; this is the second layer, for the author's own markup.
 *
 * ## The Fair Housing note sits under the body field
 *
 * PRD §10, and issue #90 is specific about where: *"Ship guidance in the
 * editor, near the body field, not buried in a help page."* Automated
 * client-facing content that describes a neighbourhood or its occupants in
 * protected-class terms is a real legal exposure, and it is written once, in a
 * template, months before anybody reads it again.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { Send } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppSelect from '@/components/app/AppSelect.vue';
import AppTextarea from '@/components/app/AppTextarea.vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';
import { formatCount } from '@/lib/formatters';

type MergeFieldRow = {
    token: string;
    label: string;
    group: string;
    description: string;
    placeholder: string;
    isAvailable: boolean;
    availableFrom: string | null;
};

type Preview = {
    subject: string | null;
    bodyHtml: string | null;
    bodyText: string;
    unresolved: string[];
    unknown: string[];
    /** Brace runs that were never a pair — `{{ client_name }` with one dropped. */
    malformed: string[];
    isComplete: boolean;
    dealId: string | null;
    recipients: string[];
};

const props = defineProps<{
    template: {
        id: string;
        name: string;
        channel: string;
        channelLabel: string;
        recipient: string;
        archivedAt: string | null;
        inUse: number;
        url: string;
        subject: string | null;
        bodyHtml: string | null;
        bodyText: string;
        fromIdentity: string | null;
        recipientRule: { type: string; participantRole?: string | null };
    };
    mergeFields: MergeFieldRow[];
    channels: Record<string, string>;
    /**
     * Keyed by channel — see the S45 list for why.
     *
     * This screen is where the hazard bites hardest: `hasSubject` and
     * `hasHtml` below are reactive on the form's channel, and this was a prop
     * carrying the **saved** one. Two of the three channel-dependent
     * narrowings updated as the reader typed and the third did not, which is
     * `docs/Frontend conventions.md` §3's *"a filtered list's props are not
     * its filters"* in its cleanest form.
     */
    recipientRules: Record<string, Record<string, string>>;
    participantRoles: Record<string, string>;
    deals: { id: string; name: string }[];
    preview: Preview | null;
    can: { update: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Templates', href: '/templates' },
            { title: 'Messages', href: '/templates/messages' },
        ],
    },
});

const base = `/templates/messages/${props.template.id}`;

const form = useForm<{
    name: string;
    channel: string;
    subject: string;
    body_html: string;
    body_text: string;
    from_identity: string;
    recipient_rule: { type: string; participantRole: string | null };
}>({
    name: props.template.name,
    channel: props.template.channel,
    subject: props.template.subject ?? '',
    body_html: props.template.bodyHtml ?? '',
    body_text: props.template.bodyText,
    from_identity: props.template.fromIdentity ?? '',
    recipient_rule: {
        type: props.template.recipientRule.type,
        // Present and null rather than absent: `AppSelect` models
        // `string | null`, and an absent key is `undefined` to the compiler.
        participantRole: props.template.recipientRule.participantRole ?? null,
    },
});

const hasSubject = computed(() => form.channel === 'email');
const hasHtml = computed(() => form.channel === 'email');

const rulesForChannel = computed<Record<string, string>>(
    () => props.recipientRules[form.channel] ?? {},
);

watch(rulesForChannel, (rules) => {
    if (!(form.recipient_rule.type in rules)) {
        form.recipient_rule.type = Object.keys(rules)[0] ?? '';
    }
});

const needsParticipantRole = computed(
    () => form.recipient_rule.type === 'participant_role',
);

const dealId = ref<string | null>(
    props.preview?.dealId ?? props.deals[0]?.id ?? null,
);

/*
 * Which field the merge-field picker inserts into.
 *
 * Tracked on focus rather than guessed, because a token belongs where the
 * cursor was — inserting into "whichever field is first" is the kind of
 * helpfulness that puts `{{ client_name }}` in the subject line of an email
 * about a property.
 */
const lastFocused = ref<'subject' | 'body_html' | 'body_text'>('body_text');

const available = computed(() =>
    props.mergeFields.filter((field) => field.isAvailable),
);
const notYet = computed(() =>
    props.mergeFields.filter((field) => !field.isAvailable),
);

function insert(field: MergeFieldRow): void {
    const target = lastFocused.value;

    if (target === 'subject' && !hasSubject.value) {
        return;
    }

    form[target] = `${form[target] ?? ''}${field.placeholder}`;
}

function save(): void {
    form.transform((data) => ({
        ...data,
        subject: hasSubject.value ? data.subject : undefined,
        body_html: hasHtml.value ? data.body_html : undefined,
        from_identity: data.from_identity === '' ? null : data.from_identity,
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
    })).patch(base, { preserveScroll: true });
}

/*
 * The draft, against the chosen deal. Deliberately *not* validated first: a
 * merge field with nothing behind it is what somebody opened the preview to
 * find, and refusing to show it would hide the answer behind the question.
 */
function refreshPreview(): void {
    if (dealId.value === null) {
        return;
    }

    router.post(
        `${base}/preview`,
        {
            deal: dealId.value,
            /*
             * The channel and the recipient rule travel with the words.
             * Without them the server rendered the draft's body against the
             * **saved** channel, so a push template switched to Email in the
             * form previewed with no subject and no HTML body — the reader's
             * own text, missing.
             */
            channel: form.channel,
            subject: hasSubject.value ? form.subject : null,
            body_html: hasHtml.value ? form.body_html : null,
            body_text: form.body_text,
            recipient_rule: {
                type: form.recipient_rule.type,
                participantRole: needsParticipantRole.value
                    ? form.recipient_rule.participantRole
                    : null,
            },
        },
        { preserveScroll: true, preserveState: true },
    );
}

const testing = ref(false);

function sendTest(): void {
    if (dealId.value === null) {
        return;
    }

    testing.value = true;

    router.post(
        `${base}/test`,
        { deal: dealId.value },
        {
            preserveScroll: true,
            onFinish: () => {
                testing.value = false;
            },
            onCancel: () => {
                testing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="template.name" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :title="template.name"
            subtitle="What this says is what the client reads. Check it against a real deal before an automation starts sending it."
        >
            <template #actions>
                <StatusBadge
                    tone="neutral"
                    :label="template.channelLabel"
                    dotless
                />
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
            </template>
        </PageHeader>

        <p
            v-if="template.archivedAt"
            class="rounded-md border bg-muted px-4 py-2.5 text-xs text-muted-foreground"
        >
            This one is archived, so it cannot be changed and no new automation
            can choose it. Restore it from the list to edit it again.
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="flex flex-col gap-4">
                <Card title="Who it goes to, and how">
                    <div class="grid gap-3 px-4 py-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="name">Name</Label>
                            <AppInput
                                id="name"
                                v-model="form.name"
                                size="default"
                                maxlength="120"
                                :disabled="!can.update"
                            />
                            <p
                                v-if="form.errors.name"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="channel">How it is sent</Label>
                            <AppSelect
                                id="channel"
                                v-model="form.channel"
                                :options="channels"
                                size="default"
                                :disabled="!can.update"
                            />
                            <p
                                v-if="form.errors.channel"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.channel }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="recipient">Who it goes to</Label>
                            <AppSelect
                                id="recipient"
                                v-model="form.recipient_rule.type"
                                :options="rulesForChannel"
                                size="default"
                                :disabled="!can.update"
                            />
                            <!--
                                A rule, never an address. A template holding an
                                address emails the wrong person the moment it
                                is reused on another deal.
                            -->
                            <p class="text-[11px] text-muted-foreground">
                                Worked out per deal when it sends, so one
                                template serves every deal.
                            </p>
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
                            <Label for="role">Which role</Label>
                            <AppSelect
                                id="role"
                                v-model="form.recipient_rule.participantRole"
                                :options="participantRoles"
                                size="default"
                                placeholder="Choose a role"
                                :disabled="!can.update"
                            />
                            <p
                                v-if="
                                    form.errors[
                                        'recipient_rule.participantRole'
                                    ]
                                "
                                class="text-[11px] text-state-danger"
                            >
                                {{
                                    form.errors[
                                        'recipient_rule.participantRole'
                                    ]
                                }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <Label for="from">Send from</Label>
                            <AppInput
                                id="from"
                                v-model="form.from_identity"
                                size="default"
                                type="email"
                                placeholder="Your team’s verified address"
                                :disabled="!can.update"
                            />
                            <!--
                                Stored and read by nothing yet. Sending
                                identities are #94, and until they land this
                                field changes no address on any message — say
                                so, rather than accepting an address in
                                silence. The same standard the merge field
                                picker holds: a deferred thing is named with
                                its slice, not left to look like it works.
                            -->
                            <p class="text-[11px] text-muted-foreground">
                                Saved with the template and not used yet — the
                                team’s sending identity is what every message
                                goes out from until per-template addresses are
                                verified.
                            </p>
                            <p
                                v-if="form.errors.from_identity"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.from_identity }}
                            </p>
                        </div>
                    </div>
                </Card>

                <Card title="What it says">
                    <div class="flex flex-col gap-4 px-4 py-4">
                        <div v-if="hasSubject" class="flex flex-col gap-1.5">
                            <Label for="subject">Subject</Label>
                            <AppInput
                                id="subject"
                                v-model="form.subject"
                                size="default"
                                maxlength="200"
                                :disabled="!can.update"
                                @focus="lastFocused = 'subject'"
                            />
                            <p
                                v-if="form.errors.subject"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.subject }}
                            </p>
                        </div>

                        <div v-if="hasHtml" class="flex flex-col gap-1.5">
                            <Label for="body_html">Formatted message</Label>
                            <AppTextarea
                                id="body_html"
                                v-model="form.body_html"
                                :rows="8"
                                :disabled="!can.update"
                                @focus="lastFocused = 'body_html'"
                            />
                            <p
                                v-if="form.errors.body_html"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.body_html }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="body_text">Plain text</Label>
                            <AppTextarea
                                id="body_text"
                                v-model="form.body_text"
                                :rows="6"
                                :disabled="!can.update"
                                @focus="lastFocused = 'body_text'"
                            />
                            <p class="text-[11px] text-muted-foreground">
                                Every message carries one. It is what a watch, a
                                screen reader, and a blocked-images inbox show.
                            </p>
                            <p
                                v-if="form.errors.body_text"
                                class="text-[11px] text-state-danger"
                            >
                                {{ form.errors.body_text }}
                            </p>
                        </div>

                        <!--
                            PRD §10, beside the body field rather than in a help
                            article. This is the one place in the product where
                            somebody writes words that will go to dozens of
                            clients without being read again.
                        -->
                        <Alert>
                            <AlertDescription>
                                Fair Housing applies to everything that goes out
                                from here. Describe the property and the process
                                — never the neighbourhood’s people, the schools
                                as a proxy for them, or who a home would “suit”.
                            </AlertDescription>
                        </Alert>

                        <div class="flex flex-col gap-2">
                            <span class="text-[11px] font-medium">
                                Merge fields — click to add one where you were
                                typing
                            </span>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="field in available"
                                    :key="field.token"
                                    type="button"
                                    class="min-h-11 rounded-md border px-2.5 text-[11px] text-muted-foreground transition-colors hover:bg-muted md:min-h-0 md:py-1"
                                    :title="field.description"
                                    :disabled="!can.update"
                                    @click="insert(field)"
                                >
                                    {{ field.label }}
                                </button>
                            </div>
                            <!--
                                The fields F5.6 names that cannot resolve yet,
                                shown with the slice that wires them. Named
                                rather than hidden: "there is no such field"
                                would send somebody looking for a spelling
                                mistake.
                            -->
                            <p
                                v-if="notYet.length > 0"
                                class="text-[11px] text-muted-foreground"
                            >
                                Not yet available:
                                {{
                                    notYet
                                        .map(
                                            (f) =>
                                                `${f.label} (${f.availableFrom})`,
                                        )
                                        .join(' · ')
                                }}
                            </p>
                        </div>

                        <div v-if="can.update" class="flex justify-end">
                            <AppButton
                                type="button"
                                :disabled="form.processing"
                                @click="save"
                                >Save template</AppButton
                            >
                        </div>
                    </div>
                </Card>
            </div>

            <Card title="Preview">
                <div class="flex flex-col gap-4 px-4 py-4">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <Label for="preview_deal">Against this deal</Label>
                            <AppSelect
                                id="preview_deal"
                                v-model="dealId"
                                :options="
                                    Object.fromEntries(
                                        deals.map((deal) => [
                                            deal.id,
                                            deal.name,
                                        ]),
                                    )
                                "
                                size="default"
                                @update:model-value="refreshPreview"
                            />
                        </div>
                        <AppButton
                            variant="ghost"
                            :disabled="dealId === null"
                            @click="refreshPreview"
                            >Refresh</AppButton
                        >
                    </div>

                    <p
                        v-if="deals.length === 0"
                        class="text-[11px] text-muted-foreground"
                    >
                        There are no deals to preview against yet. Open one and
                        come back — a preview against invented data proves
                        nothing.
                    </p>

                    <template v-if="preview">
                        <!--
                            The problems, above the words rather than below
                            them. #93 asks for an unresolved merge field to be
                            impossible to miss, because it is what blocks a send.
                        -->
                        <Alert v-if="!preview.isComplete" variant="destructive">
                            <AlertDescription>
                                <!--
                                    First, because it is the one that renders
                                    into the client's inbox verbatim: the
                                    substitution has no pair to replace.
                                -->
                                <span v-if="preview.malformed.length > 0">
                                    There is a stray
                                    {{ preview.malformed.join(' and ') }} in
                                    here — a merge field needs both braces at
                                    both ends.
                                </span>
                                <span v-if="preview.unknown.length > 0">
                                    No such merge field:
                                    {{ preview.unknown.join(', ') }}.
                                </span>
                                <span v-if="preview.unresolved.length > 0">
                                    Nothing behind these on this deal:
                                    {{ preview.unresolved.join(', ') }}.
                                </span>
                                This cannot be sent until they are fixed.
                            </AlertDescription>
                        </Alert>

                        <div class="flex flex-col gap-1">
                            <span class="text-[11px] text-muted-foreground"
                                >Would reach</span
                            >
                            <span class="text-13">
                                {{
                                    preview.recipients.length > 0
                                        ? preview.recipients.join(', ')
                                        : 'Nobody on this deal — check the recipient rule.'
                                }}
                            </span>
                        </div>

                        <div
                            v-if="preview.subject !== null"
                            class="flex flex-col gap-1"
                        >
                            <span class="text-[11px] text-muted-foreground"
                                >Subject</span
                            >
                            <span class="text-13 font-medium">{{
                                preview.subject
                            }}</span>
                        </div>

                        <!--
                            Sandboxed with no allowances: no scripts, no forms,
                            no same-origin. See the file docblock.
                        -->
                        <iframe
                            v-if="preview.bodyHtml"
                            :srcdoc="preview.bodyHtml"
                            sandbox=""
                            title="Formatted message preview"
                            class="h-64 w-full rounded-md border bg-background"
                        ></iframe>

                        <div class="flex flex-col gap-1">
                            <span class="text-[11px] text-muted-foreground"
                                >Plain text</span
                            >
                            <pre
                                class="overflow-x-auto rounded-md border bg-muted p-3 text-xs whitespace-pre-wrap"
                                >{{ preview.bodyText }}</pre>
                        </div>

                        <div v-if="template.channel === 'email'" class="flex">
                            <AppButton
                                variant="ghost"
                                :disabled="
                                    testing || dealId === null || !can.update
                                "
                                @click="sendTest"
                            >
                                <Send class="size-4" aria-hidden="true" />
                                Send a test to me
                            </AppButton>
                        </div>
                    </template>
                </div>
            </Card>
        </div>
    </div>
</template>
