<script setup lang="ts">
/**
 * S47 — the message approval queue (PRD §4.5 F5.7, F5.8 · issue #93).
 *
 * PRD §4.5 calls this a **launch blocker, not an enhancement**, and the
 * sentence behind it is one this screen is shaped by: *"an automation that
 * emails the wrong client the wrong thing damages a real relationship and
 * cannot be recalled."*
 *
 * ## No bulk approve, and it is a feature rather than an omission
 *
 * #93 names the failure mode outright — *"bulk approve teaches people to
 * approve without reading"*. Every release is one message, on its own page,
 * with its words on screen. The queue is deliberately a little tedious, in the
 * way a checklist is tedious.
 *
 * ## The failures are on this screen, not another one
 *
 * *"Has the client been told?"* is PRD §1.1's second question, and a message
 * that failed answers it exactly as badly as one still waiting. Putting the
 * failures behind their own tab is how a team goes a fortnight without
 * noticing their mail credentials expired.
 */
import { Head, Link } from '@inertiajs/vue3';
import { MailCheck, MailX } from '@lucide/vue';
import { computed } from 'vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { formatCount, formatRelativeDate } from '@/lib/formatters';

type MessageRow = {
    id: string;
    state: string;
    stateLabel: string;
    actionType: string;
    actionLabel: string;
    trigger: string;
    triggerLabel: string;
    dealId: string | null;
    dealName: string | null;
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
};

const props = defineProps<{
    waiting: MessageRow[];
    /**
     * Their own list, from their own query.
     *
     * These used to be filtered out of `recent` here, which made *"did this go
     * out?"* a question about how busy the team had been since — a failure fell
     * off the screen after 25 more messages, on the list `automation.md`
     * promises is *"the thing you most need to notice"*.
     */
    failing: MessageRow[];
    recent: MessageRow[];
    /** The real counts, so a truncated list can say it is truncated. */
    totals: { waiting: number; failing: number };
    can: { approve: boolean };
}>();

const moreWaiting = computed(() => props.totals.waiting - props.waiting.length);
const moreFailing = computed(() => props.totals.failing - props.failing.length);

/**
 * Who it names, never how many.
 *
 * A queue row that said "3 recipients" would be a row somebody could approve
 * without knowing whether the sellers or the opposing agent were among them,
 * which is the one thing this screen exists to make them look at.
 */
function recipientNames(message: MessageRow): string {
    return message.recipients.length === 0
        ? 'nobody'
        : message.recipients.map((recipient) => recipient.name).join(', ');
}
</script>

<template>
    <Head title="Messages" />

    <div class="flex flex-col gap-4 p-4 md:p-6">
        <PageHeader
            title="Messages"
            subtitle="What your automations are about to send, and what they have already sent."
        />

        <!--
            The failures first, above the queue, and only when there are any.

            Deliberately out of chronological order: everything else on this
            screen is something waiting on a decision, and this is something
            that already went wrong. A team scrolling past it to reach the
            queue is a team that has not been told.
        -->
        <Card v-if="failing.length > 0" title="Did not go out">
            <ul class="divide-y divide-border">
                <li
                    v-for="message in failing"
                    :key="message.id"
                    class="flex flex-col gap-1 px-4 py-3"
                >
                    <div class="flex items-start gap-2">
                        <MailX
                            class="mt-0.5 size-4 shrink-0 text-state-danger"
                            aria-hidden="true"
                        />
                        <div class="flex flex-col gap-0.5">
                            <TextLink :href="`/messages/${message.id}`">
                                {{ message.subject ?? message.actionLabel }}
                            </TextLink>
                            <p class="text-13 text-muted-foreground">
                                {{ message.error }}
                            </p>
                        </div>
                    </div>
                </li>
            </ul>

            <p
                v-if="moreFailing > 0"
                class="border-t border-border px-4 py-2.5 text-13 text-muted-foreground"
            >
                and {{ moreFailing }} more.
            </p>
        </Card>

        <Card title="Waiting for review">
            <EmptyState
                v-if="waiting.length === 0"
                :icon="MailCheck"
                title="Nothing is waiting"
                description="Automated messages appear here before they go out, so somebody can read them first."
            />

            <ul v-else class="divide-y divide-border">
                <li
                    v-for="message in waiting"
                    :key="message.id"
                    class="flex flex-col gap-1.5 px-4 py-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            domain="automation"
                            :state="message.state"
                        />
                        <TextLink :href="`/messages/${message.id}`">
                            {{ message.subject ?? message.actionLabel }}
                        </TextLink>
                        <span
                            v-if="!message.isComplete"
                            class="rounded-md bg-state-danger-bg px-1.5 py-0.5 text-[11px] font-medium text-state-danger"
                        >
                            Needs fixing before it can go
                        </span>
                    </div>

                    <p class="text-13 text-muted-foreground">
                        To {{ recipientNames(message) }}
                        <template v-if="message.dealName">
                            ·
                            <TextLink
                                v-if="message.dealId"
                                :href="`/deals/${message.dealId}`"
                            >
                                {{ message.dealName }}
                            </TextLink>
                        </template>
                        · {{ message.triggerLabel.toLowerCase() }}
                        <template v-if="message.raisedAt">
                            · {{ formatRelativeDate(message.raisedAt) }}
                        </template>
                    </p>
                </li>
            </ul>

            <!--
                The count under the list rather than in the heading, because
                the heading is what the sidebar's badge already says. This
                sentence is the one that names the rule: one at a time.
            -->
            <p
                v-if="waiting.length > 0"
                class="border-t border-border px-4 py-2.5 text-13 text-muted-foreground"
            >
                {{ formatCount(totals.waiting, 'message') }} waiting<template
                    v-if="moreWaiting > 0"
                    >, {{ moreWaiting }} of them not shown</template
                >.
                <template v-if="can.approve">
                    Open one to read it before it goes out.
                </template>
                <template v-else>
                    Somebody with permission to approve messages has to release
                    these.
                </template>
            </p>
        </Card>

        <Card v-if="recent.length > 0" title="Recently">
            <ul class="divide-y divide-border">
                <li
                    v-for="message in recent"
                    :key="message.id"
                    class="flex flex-wrap items-center gap-2 px-4 py-2.5"
                >
                    <StatusBadge domain="automation" :state="message.state" />
                    <TextLink :href="`/messages/${message.id}`">
                        {{ message.subject ?? message.actionLabel }}
                    </TextLink>
                    <span class="text-13 text-muted-foreground">
                        {{ recipientNames(message) }}
                        <template v-if="message.executedAt">
                            · {{ formatRelativeDate(message.executedAt) }}
                        </template>
                    </span>
                </li>
            </ul>
        </Card>

        <p class="text-13 text-muted-foreground">
            The words come from your
            <Link href="/templates/messages" class="underline">
                message templates
            </Link>
            , and which automation sends them is set on the workflow template.
        </p>
    </div>
</template>
