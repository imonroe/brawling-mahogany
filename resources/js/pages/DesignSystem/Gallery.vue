<script setup lang="ts">
/**
 * The component gallery — an internal review surface, never served in
 * production (see routes/web.php).
 *
 * Every token pair and every component in `components/app/` renders here, in
 * both themes, so a change can be judged rather than guessed at.
 */
import { Head } from '@inertiajs/vue3';
import {
    Briefcase,
    CircleCheck,
    Funnel,
    Mail,
    ShieldAlert,
    Search,
} from '@lucide/vue';
import { ref } from 'vue';
import ActivityItem from '@/components/app/ActivityItem.vue';
import AppButton from '@/components/app/AppButton.vue';
import AppInput from '@/components/app/AppInput.vue';
import Card from '@/components/app/Card.vue';
import DateChip from '@/components/app/DateChip.vue';
import { dealRowColumns } from '@/components/app/dealRow';
import DealRow from '@/components/app/DealRow.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FilterBar from '@/components/app/FilterBar.vue';
import FilterChip from '@/components/app/FilterChip.vue';
import IconButton from '@/components/app/IconButton.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import PersonAvatar from '@/components/app/PersonAvatar.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import Tab from '@/components/app/Tab.vue';
import TabBar from '@/components/app/TabBar.vue';
import Table from '@/components/app/Table.vue';
import TaskItem from '@/components/app/TaskItem.vue';
import { STATES } from '@/lib/states';
import type { StateDomain } from '@/lib/states';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Design system', href: '/design-system' }],
    },
});

const domains: StateDomain[] = [
    'deal',
    'workflow',
    'stage',
    'task',
    'gate',
    'person',
    'automation',
    'extractedField',
    'document',
];

const columns = dealRowColumns({ selectable: true });
const dashboardColumns = dealRowColumns({
    hide: ['meta1'],
    widths: { meta2: 120 },
});

const search = ref('');
const segment = ref('today');

// Fixed instants so the gallery renders identically on every visit.
const now = new Date('2026-08-20T15:00:00Z');
const soon = new Date('2026-08-23T15:00:00Z');
const past = new Date('2026-08-12T15:00:00Z');

const owner = { firstName: 'Heather', lastName: 'Nguyen' };
</script>

<template>
    <Head title="Design system" />

    <div class="flex flex-col gap-6 p-6">
        <PageHeader
            title="Design system"
            subtitle="Every token pair and app component, in both themes"
        />

        <template v-for="theme in ['light', 'dark']" :key="theme">
            <section :class="theme === 'dark' ? 'dark' : ''">
                <div
                    class="flex flex-col gap-6 rounded-lg bg-background p-6 text-foreground"
                >
                    <h2 class="text-lg font-semibold capitalize">
                        {{ theme }}
                    </h2>

                    <Card title="State badges — every state in IA §8">
                        <div class="flex flex-col gap-4 p-4">
                            <div
                                v-for="domain in domains"
                                :key="domain"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <span
                                    class="w-32 text-xs font-medium text-muted-foreground"
                                    >{{ domain }}</span
                                >
                                <StatusBadge
                                    v-for="(descriptor, code) in STATES[domain]"
                                    :key="code"
                                    :domain="domain"
                                    :state="String(code)"
                                />
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="w-32 text-xs font-medium text-muted-foreground"
                                    >dotless</span
                                >
                                <StatusBadge tone="warning" dotless label="4" />
                                <StatusBadge
                                    tone="success"
                                    dotless
                                    label="Met"
                                />
                                <StatusBadge
                                    tone="neutral"
                                    dotless
                                    label="3 of 11 reviewed"
                                />
                            </div>
                        </div>
                    </Card>

                    <Card title="Controls — the measured sizes (§4.2, §7.2)">
                        <div class="flex flex-col gap-4 p-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <AppButton>Advance Stage</AppButton>
                                <AppButton variant="secondary"
                                    >Add Task</AppButton
                                >
                                <AppButton variant="ghost" size="ghost"
                                    >Log Contact</AppButton
                                >
                                <AppButton variant="warning"
                                    >Override and Advance</AppButton
                                >
                                <AppButton variant="destructive"
                                    >Delete</AppButton
                                >
                                <AppButton disabled>Advance Stage</AppButton>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <AppButton size="compact">Confirm</AppButton>
                                <AppButton size="compact" variant="secondary"
                                    >Edit</AppButton
                                >
                                <AppButton size="compact" variant="ghost"
                                    >Reject</AppButton
                                >
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <!--
                                    §11: every input bound to a label, and a
                                    placeholder is not a label. The gallery has
                                    no visible labels, so each field carries an
                                    aria-label rather than modelling the
                                    anti-pattern beside the components that get
                                    it right.
                                -->
                                <AppInput
                                    aria-label="Form control example"
                                    class="w-[240px]"
                                    placeholder="Form control, 40px"
                                />
                                <AppInput
                                    size="filter"
                                    aria-label="Filter control example"
                                    class="w-[160px]"
                                    placeholder="Filter, 32px"
                                />
                            </div>
                        </div>
                    </Card>

                    <Card title="Atoms">
                        <div class="flex flex-wrap items-center gap-4 p-4">
                            <DateChip :date="soon" :now="now" />
                            <DateChip :date="past" :now="now" />
                            <DateChip :date="soon" :now="now" relative />
                            <IconButton :icon="Search" label="Search" />
                            <IconButton :icon="Mail" label="Messages" unread />
                            <PersonAvatar :person="owner" :size="20" />
                            <PersonAvatar :person="owner" :size="24" />
                            <PersonAvatar :person="owner" :size="32" />
                            <PersonAvatar :person="owner" :size="46" brand />
                        </div>
                    </Card>

                    <Card title="List page kit">
                        <div class="flex flex-col gap-4 p-4">
                            <FilterBar
                                v-model="search"
                                placeholder="Search deals"
                            >
                                <FilterChip
                                    filter-key="Status"
                                    value="Active"
                                    active
                                />
                                <FilterChip filter-key="Owner" value="Anyone" />
                                <template #controls>
                                    <IconButton
                                        :icon="Funnel"
                                        label="More filters"
                                    />
                                </template>
                            </FilterBar>

                            <SegmentedControl
                                v-model="segment"
                                :segments="[
                                    {
                                        value: 'today',
                                        label: 'Today',
                                        count: 3,
                                    },
                                    {
                                        value: 'week',
                                        label: 'This week',
                                        count: 11,
                                    },
                                    { value: 'all', label: 'All', count: 42 },
                                ]"
                            />

                            <TabBar>
                                <Tab label="Overview" active />
                                <Tab label="Tasks" :count="7" />
                                <Tab label="Documents" :count="3" />
                            </TabBar>

                            <Table :columns="columns" footer-note="2 deals">
                                <DealRow
                                    :columns="columns"
                                    primary="123 Main St"
                                    meta1="Emily Bosart"
                                    meta2="Under Contract"
                                    state="active"
                                    :date="soon"
                                    :owner="owner"
                                />
                                <DealRow
                                    :columns="columns"
                                    primary="Bosart Purchase"
                                    meta1="Emily Bosart"
                                    meta2="Closed"
                                    state="closed"
                                    :date="past"
                                    :owner="owner"
                                />
                            </Table>

                            <Table
                                :columns="dashboardColumns"
                                footer-note="Dashboard variant"
                            >
                                <DealRow
                                    :columns="dashboardColumns"
                                    primary="123 Main St"
                                    meta2="Under Contract"
                                    state="active"
                                    :date="soon"
                                    :owner="owner"
                                />
                            </Table>
                        </div>
                    </Card>

                    <div class="grid gap-6 md:grid-cols-2">
                        <Card title="Tasks">
                            <TaskItem
                                title="Order the inspection"
                                meta="123 Main St · Under Contract"
                                :due-date="soon"
                                :assignee="owner"
                            />
                            <TaskItem
                                title="Send the disclosure packet"
                                meta="Completed by Heather"
                                completed
                                :due-date="past"
                                :assignee="owner"
                            />
                        </Card>

                        <Card title="Activity">
                            <div class="px-4">
                                <ActivityItem
                                    :icon="CircleCheck"
                                    tone="success"
                                    text="Heather completed “Order the inspection”"
                                    time="Thu, Aug 20 at 2:30pm"
                                />
                                <ActivityItem
                                    :icon="Mail"
                                    tone="info"
                                    text="Milestone email sent to Emily Bosart"
                                    time="Thu, Aug 20 at 9:04am"
                                />
                                <ActivityItem
                                    :icon="ShieldAlert"
                                    tone="warning"
                                    text="Emily overrode “Inspection report received”"
                                    time="Wed, Aug 19 at 4:12pm"
                                />
                            </div>
                        </Card>
                    </div>

                    <div class="grid gap-6 md:grid-cols-2">
                        <Card title="Empty — a new team’s dashboard">
                            <EmptyState
                                :icon="Briefcase"
                                title="No deals yet"
                                description="When a deal is running, this is where the blocked stages, the late tasks, and the next fortnight’s deadlines appear."
                            />
                        </Card>
                        <Card title="Empty — the deals index, filtered">
                            <EmptyState
                                :icon="Funnel"
                                variant="filtered"
                                title="No deals match these filters"
                                description="Clear the filters to see all 25 active deals."
                            />
                        </Card>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
