<script setup lang="ts">
/**
 * S11 — My Work (PRD §4.9 F9.2 · Design System §8.6, §9.2 · #80).
 *
 * *"Every task assigned to me across all deals, ordered by urgency. Heather's
 * primary screen."* PRD §3.4 has her opening it on a phone between showings,
 * which decides most of what follows: the segmented control answers the only
 * question she has on arrival, and the rows carry the deal name because a task
 * with no context is a task she has to open a second screen to understand.
 *
 * ## Grouped by deal, ordered by urgency, and those are not in tension
 *
 * `tasks.deal_id` is not nullable *"because My Work groups by deal"* — the
 * schema decided that before this screen existed. The rows are sorted once, by
 * urgency, and the groups fall out in that order, so the deal holding the most
 * overdue thing is the deal at the top. Sorting the groups by anything else —
 * name, deal age — would bury the thing she came here for.
 *
 * ## No assignee avatar
 *
 * Design System §7.3: *"the assignee avatar is hidden on My Work, where it is
 * always the current user."* A column that says the same thing on every row is
 * a column that costs width and carries nothing.
 */
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import TaskItem from '@/components/app/TaskItem.vue';
import TextLink from '@/components/app/TextLink.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatCount } from '@/lib/formatters';

type WorkTask = {
    id: string;
    title: string;
    state: string;
    isRequired: boolean;
    dueDate: string | null;
    completedAt: string | null;
};

type WorkGroup = {
    dealId: string;
    dealName: string;
    dealUrl: string;
    tasks: WorkTask[];
};

const props = defineProps<{
    segment: string;
    groups: WorkGroup[];
    counts: { open: number; overdue: number; all: number; deals: number };
}>();

const { can } = usePermissions();

/**
 * §8.5's subtitle, which the Design System writes out for this exact screen:
 * *"12 tasks assigned to you across 7 deals · 3 overdue"*.
 */
const subtitle = computed(() => {
    const { open, deals, overdue } = props.counts;

    if (open === 0) {
        return 'Nothing assigned to you right now.';
    }

    const line = `${formatCount(open, 'task')} assigned to you across ${formatCount(deals, 'deal')}`;

    return overdue > 0 ? `${line} · ${overdue} overdue` : line;
});

const segments = computed(() => [
    { value: 'open', label: 'Open', count: props.counts.open },
    { value: 'overdue', label: 'Overdue', count: props.counts.overdue },
    { value: 'all', label: 'All', count: props.counts.all },
]);

function show(segment: string): void {
    router.get(
        '/work',
        { segment },
        { preserveScroll: true, preserveState: true },
    );
}

/**
 * The same endpoint S16 and S17 post to (#71).
 *
 * `back()` on the server means the reply re-renders this screen, so a ticked
 * task leaves its segment on the spot — which is what a queue is for.
 */
function setCompleted(
    dealUrl: string,
    taskId: string,
    completed: boolean,
): void {
    const url = `${dealUrl}/tasks/${taskId}/completion`;
    const visit = { preserveScroll: true, preserveState: true };

    if (completed) {
        router.post(url, {}, visit);

        return;
    }

    router.delete(url, visit);
}

const empty = computed(() => props.groups.length === 0);
</script>

<template>
    <Head title="My Work" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="My Work" :subtitle="subtitle">
            <template #actions>
                <SegmentedControl
                    :model-value="segment"
                    :segments="segments"
                    @update:model-value="show"
                />
            </template>
        </PageHeader>

        <!--
            IA §10's empty-state rule: say what belongs here, then offer the
            action that creates it. The three segments need three sentences —
            "nothing overdue" is good news and must not read like an error.
        -->
        <EmptyState
            v-if="empty"
            :title="
                segment === 'overdue'
                    ? 'Nothing is overdue'
                    : 'Nothing is assigned to you'
            "
            :description="
                segment === 'overdue'
                    ? 'Everything with a date on it is still ahead of you.'
                    : 'Tasks people assign to you on any deal show up here, most urgent first.'
            "
        >
            <template v-if="can('deals.view')" #action>
                <TextLink href="/deals">Go to deals</TextLink>
            </template>
        </EmptyState>

        <div v-else class="flex flex-col gap-4">
            <!--
                One card per deal, in the order the urgency sort put them. The
                deal name is the card's header rather than a repeated cell on
                every row — §7.3's row already carries enough.
            -->
            <Card
                v-for="group in groups"
                :key="group.dealId"
                :title="group.dealName"
            >
                <template #action>
                    <TextLink :href="group.dealUrl">Open deal</TextLink>
                </template>

                <ul class="flex flex-col">
                    <li
                        v-for="task in group.tasks"
                        :key="task.id"
                        class="border-b last:border-b-0"
                    >
                        <TaskItem
                            :title="task.title"
                            :completed="task.completedAt !== null"
                            :due-date="task.dueDate"
                            :readonly="!can('deals.manage')"
                            @update:completed="
                                (value) =>
                                    setCompleted(group.dealUrl, task.id, value)
                            "
                        />
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
