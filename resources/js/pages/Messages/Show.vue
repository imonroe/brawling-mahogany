<script setup lang="ts">
/**
 * S48 and S49 — one message: what it says, and what happened to it
 * (PRD §4.5 F5.7, F5.8, F5.10 · issue #93).
 *
 * ## The preview is the message, not a summary of it
 *
 * F5.10 pre-fills an outbound email *"ready to review and send"*, and reviewing
 * means reading the words a client will read. So the body is on the page, in
 * full, and it is editable — the edit lands on **this instance's** payload and
 * never on the template, because two instances raised from one template are
 * two messages to two clients.
 *
 * ## The HTML body renders in a sandboxed iframe
 *
 * Never `v-html`. S46's editor made the same choice for the same reason: a
 * template body is markup somebody with `templates.manage` wrote, and this
 * screen is opened by a colleague. The test *send* may render it as markup
 * because it can only reach its own author; a shared screen may not.
 *
 * ## Approving is blocked while a merge field is unfilled
 *
 * #93: *"a missing merge field blocks approval."* The button is disabled and
 * the reason is named — the server refuses it too, which is what actually
 * holds, but a disabled button with an explanation beside it is the difference
 * between a rule and a surprise.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, MailCheck, Ban } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import AppTextarea from '@/components/app/AppTextarea.vue';
import Card from '@/components/app/Card.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/formatters';

type Message = {
    id: string;
    state: string;
    stateLabel: string;
    actionType: string;
    actionLabel: string;
    trigger: string;
    triggerLabel: string;
    dealId: string | null;
    dealName: string | null;
    stageName: string | null;
    templateName: string | null;
    subject: string | null;
    recipients: { name: string; email: string }[];
    isComplete: boolean;
    problems: {
        malformed: string[];
        unknown: string[];
        unresolved: string[];
    };
    error: string | null;
    raisedAt: string | null;
    executedAt: string | null;
    approvedAt: string | null;
    attempts: number;
    rendered: {
        subject: string | null;
        bodyHtml: string | null;
        bodyText: string;
    };
};

const props = defineProps<{
    message: Message;
    can: { approve: boolean; cancel: boolean };
}>();

const editing = ref(false);

const form = useForm({
    subject: props.message.rendered.subject ?? '',
    bodyText: props.message.rendered.bodyText,
    bodyHtml: props.message.rendered.bodyHtml ?? '',
});

const waiting = computed(() => props.message.state === 'awaiting_approval');

/**
 * The one sentence naming everything wrong with the message.
 *
 * Three lists, deliberately not flattened: they fail three different ways and
 * have three different fixes. #175's review found a version that merged them
 * and told an author *"these merge fields had nothing behind them: {{"*.
 */
const problems = computed<string[]>(() => {
    const { malformed, unknown, unresolved } = props.message.problems;
    const lines: string[] = [];

    if (malformed.length > 0) {
        lines.push(
            `A merge field is missing a brace, so it would go out as written — look for “${malformed.join('” and “')}”.`,
        );
    }

    if (unknown.length > 0) {
        lines.push(`No merge field is called ${unknown.join(', ')}.`);
    }

    if (unresolved.length > 0) {
        lines.push(
            `These had nothing behind them on this deal: ${unresolved.join(', ')}.`,
        );
    }

    return lines;
});

/**
 * The body in an iframe, as a data URL, with no script and no same-origin.
 *
 * `srcdoc` plus `sandbox=""` is the narrowest thing that still renders email
 * HTML: no scripts, no forms, no access to this document. Exactly what S46's
 * preview does, and for the same reason.
 */
const previewDocument = computed(() => props.message.rendered.bodyHtml ?? '');

function approve(): void {
    /*
     * Only the fields somebody actually opened the editor for. The server
     * treats an absent key as *"I did not touch this"*, so posting all three
     * unconditionally would turn "approve as written" into an edit — which
     * matters for a push message that has no HTML body and would be sent an
     * empty string for one.
     */
    if (!editing.value) {
        router.post(
            `/messages/${props.message.id}/approval`,
            {},
            { preserveScroll: true },
        );

        return;
    }

    form.transform((data) => ({
        subject:
            props.message.rendered.subject === null ? undefined : data.subject,
        bodyText: data.bodyText,
        bodyHtml:
            props.message.rendered.bodyHtml === null
                ? undefined
                : data.bodyHtml,
    })).post(`/messages/${props.message.id}/approval`, {
        preserveScroll: true,
    });
}

function cancel(): void {
    const reason = window.prompt(
        'Stop this message? Say why, if it helps whoever reads this later.',
    );

    // `null` is the cancel button on the prompt; an empty string is somebody
    // who chose not to give a reason, which the server allows.
    if (reason === null) {
        return;
    }

    router.delete(`/messages/${props.message.id}/approval`, {
        data: { reason },
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="message.subject ?? 'Message'" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :title="message.subject ?? message.actionLabel"
            :subtitle="`${message.actionLabel} · ${message.triggerLabel.toLowerCase()}`"
        >
            <template #actions>
                <AppButton variant="ghost" as="a" href="/messages">
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    All messages
                </AppButton>
            </template>
        </PageHeader>

        <Card title="What happened">
            <dl class="grid gap-3 px-4 py-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <dt class="text-[11px] text-muted-foreground uppercase">
                        State
                    </dt>
                    <dd>
                        <StatusBadge
                            domain="automation"
                            :state="message.state"
                        />
                    </dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-[11px] text-muted-foreground uppercase">
                        Goes to
                    </dt>
                    <dd class="text-13">
                        <template v-if="message.recipients.length > 0">
                            <span
                                v-for="recipient in message.recipients"
                                :key="recipient.email"
                                class="block"
                            >
                                {{ recipient.name }} ({{ recipient.email }})
                            </span>
                        </template>
                        <span v-else class="text-state-danger">
                            Nobody on this deal matched the template's rule.
                        </span>
                    </dd>
                </div>

                <div v-if="message.dealName" class="flex flex-col gap-1">
                    <dt class="text-[11px] text-muted-foreground uppercase">
                        Deal
                    </dt>
                    <dd class="text-13">
                        <TextLink
                            v-if="message.dealId"
                            :href="`/deals/${message.dealId}`"
                        >
                            {{ message.dealName }}
                        </TextLink>
                        <template v-if="message.stageName">
                            · {{ message.stageName }}
                        </template>
                    </dd>
                </div>

                <div v-if="message.templateName" class="flex flex-col gap-1">
                    <dt class="text-[11px] text-muted-foreground uppercase">
                        Template
                    </dt>
                    <dd class="text-13">{{ message.templateName }}</dd>
                </div>

                <div v-if="message.raisedAt" class="flex flex-col gap-1">
                    <dt class="text-[11px] text-muted-foreground uppercase">
                        Raised
                    </dt>
                    <dd class="text-13">
                        {{ formatDateTime(message.raisedAt) }}
                    </dd>
                </div>

                <div v-if="message.executedAt" class="flex flex-col gap-1">
                    <dt class="text-[11px] text-muted-foreground uppercase">
                        {{ message.state === 'sent' ? 'Sent' : 'Finished' }}
                    </dt>
                    <dd class="text-13">
                        {{ formatDateTime(message.executedAt) }}
                        <template v-if="message.attempts > 1">
                            · after {{ message.attempts }} attempts
                        </template>
                    </dd>
                </div>
            </dl>

            <p
                v-if="message.error"
                class="border-t border-border bg-state-danger-bg px-4 py-3 text-13 text-state-danger"
            >
                {{ message.error }}
            </p>
        </Card>

        <div
            v-if="problems.length > 0"
            class="rounded-md bg-state-danger-bg px-4 py-3 text-13 text-state-danger"
        >
            <p v-for="line in problems" :key="line">{{ line }}</p>
        </div>

        <Card title="The message">
            <template #action>
                <AppButton
                    v-if="waiting && can.approve"
                    variant="ghost"
                    size="compact"
                    @click="editing = !editing"
                >
                    {{ editing ? 'Stop editing' : 'Edit before sending' }}
                </AppButton>
            </template>

            <div class="flex flex-col gap-4 px-4 py-4">
                <template v-if="editing">
                    <div
                        v-if="message.rendered.subject !== null"
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="subject">Subject</Label>
                        <AppInput
                            id="subject"
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
                        <Label for="bodyText">Plain text</Label>
                        <AppTextarea
                            id="bodyText"
                            v-model="form.bodyText"
                            :rows="10"
                        />
                        <p class="text-[11px] text-muted-foreground">
                            These words are already filled in, so
                            <code>{{ '{{ client_name }}' }}</code> here would go
                            to the client exactly as written. Type the value
                            instead.
                        </p>
                        <p
                            v-if="form.errors.bodyText"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.bodyText }}
                        </p>
                    </div>

                    <div
                        v-if="message.rendered.bodyHtml !== null"
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="bodyHtml">HTML</Label>
                        <AppTextarea
                            id="bodyHtml"
                            v-model="form.bodyHtml"
                            :rows="10"
                            class="font-mono text-[12px]"
                        />
                        <p
                            v-if="form.errors.bodyHtml"
                            class="text-[11px] text-state-danger"
                        >
                            {{ form.errors.bodyHtml }}
                        </p>
                    </div>
                </template>

                <template v-else>
                    <iframe
                        v-if="previewDocument"
                        :srcdoc="previewDocument"
                        sandbox=""
                        title="Message preview"
                        class="h-96 w-full rounded-md border border-border bg-background"
                    ></iframe>

                    <pre
                        class="font-sans text-13 whitespace-pre-wrap text-foreground"
                        >{{ message.rendered.bodyText }}</pre>
                </template>
            </div>

            <div
                v-if="waiting && (can.approve || can.cancel)"
                class="flex flex-wrap items-center gap-2 border-t border-border px-4 py-3"
            >
                <AppButton
                    v-if="can.approve"
                    :disabled="!message.isComplete && !editing"
                    @click="approve"
                >
                    <MailCheck class="size-4" aria-hidden="true" />
                    {{
                        message.actionType === 'manual_prompt'
                            ? 'Mark done'
                            : 'Approve and send'
                    }}
                </AppButton>

                <AppButton v-if="can.cancel" variant="ghost" @click="cancel">
                    <Ban class="size-4" aria-hidden="true" />
                    Stop this message
                </AppButton>

                <p
                    v-if="!message.isComplete && !editing"
                    class="text-13 text-muted-foreground"
                >
                    Fix the merge fields above before this can go out.
                </p>
            </div>
        </Card>
    </div>
</template>
