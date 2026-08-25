<script setup lang="ts">
/**
 * S10 — the team dashboard (PRD §4.9 F9.1 · Design System §9.3 · #79).
 *
 * *"Designed for 25 concurrent active deals."* G8 records that Emily pushed
 * for 25 over Ian's proposed 12, and the Screen Inventory calls the hard part
 * *"25 deals legible at once, with late and blocked obvious"* — which is why
 * the composition puts one **Needs attention** panel above the full list
 * rather than making somebody scan twenty-five rows for the three that matter.
 *
 * §9.3's P4 pattern, unchanged: a stat row of four, then two columns — the
 * lists on the left, the dates and the activity in a 352px rail on the right.
 *
 * The empty state is the one this screen shipped with in Slice 1, and it is
 * still the first thing a new team meets. It now also covers somebody without
 * `deals.view`: the same picture, because "no deals here" and "no deals for
 * you" look alike and neither is an error.
 */
import { Head } from '@inertiajs/vue3';
import {
    Briefcase,
    CalendarClock,
    CircleAlert,
    ShieldAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import ActivityItem from '@/components/app/ActivityItem.vue';
import AppButton from '@/components/app/AppButton.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatCard from '@/components/app/StatCard.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import TextLink from '@/components/app/TextLink.vue';
import { activityDescriptor } from '@/lib/activity';
import { formatCount, formatDateShort } from '@/lib/formatters';
import type { ActivityFeedRow } from '@/types';

type DashboardDeal = {
    id: string;
    name: string;
    url: string;
    stageName: string | null;
    stageState: string | null;
    nextDueDate: string | null;
    isBlocked?: boolean;
    overdueCount?: number;
};

const props = defineProps<{
    canSeeDeals: boolean;
    stats: {
        activeDeals: number;
        blockedStages: number;
        overdueTasks: number;
        dueSoon: number;
    };
    needsAttention: DashboardDeal[];
    deals: DashboardDeal[];
    dueSoon: DashboardDeal[];
    activity: ActivityFeedRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
    },
});

const empty = computed(
    () => !props.canSeeDeals || props.stats.activeDeals === 0,
);

const subtitle = computed(() => {
    if (empty.value) {
        return 'No deals yet · nothing due';
    }

    const { activeDeals, overdueTasks } = props.stats;
    const line = `${formatCount(activeDeals, 'active deal')}`;

    return overdueTasks > 0
        ? `${line} · ${overdueTasks} overdue`
        : `${line} · nothing overdue`;
});

const entries = computed(() =>
    props.activity.map((event) => ({ event, ...activityDescriptor(event) })),
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Dashboard" :subtitle="subtitle" />

        <div v-if="empty" class="flex flex-1 rounded-lg border bg-card">
            <EmptyState
                :icon="Briefcase"
                title="No deals yet"
                description="When a deal is running, this is where the blocked stages, the late tasks, and the next fortnight’s deadlines appear."
            >
                <template v-if="canSeeDeals" #action>
                    <AppButton href="/deals">Create your first deal</AppButton>
                </template>
            </EmptyState>
        </div>

        <template v-else>
            <!-- §9.3: four stats, fixed. They answer "is anything on fire". -->
            <div class="flex flex-col gap-4 sm:flex-row">
                <StatCard
                    label="Active deals"
                    :value="stats.activeDeals"
                    :icon="Briefcase"
                    note="Running now"
                />
                <StatCard
                    label="Blocked stages"
                    :value="stats.blockedStages"
                    :icon="ShieldAlert"
                    tone="warning"
                    note="As of the last advance"
                />
                <StatCard
                    label="Overdue tasks"
                    :value="stats.overdueTasks"
                    :icon="CircleAlert"
                    tone="danger"
                    note="Across the team"
                />
                <!--
                    Not "Closing in 14 days", which §9.3 asks for and this
                    product cannot answer: `key_dates` is S18, in Slice 4. The
                    tile counts what is genuinely due and is named for it,
                    rather than claiming a closing date the database does not
                    hold. Recorded as a departure in the Screen Inventory.
                -->
                <StatCard
                    label="Due in 14 days"
                    :value="stats.dueSoon"
                    :icon="CalendarClock"
                    note="Deals with something due"
                />
            </div>

            <div class="flex flex-1 flex-col gap-6 lg:flex-row">
                <div class="flex min-w-0 flex-1 flex-col gap-6">
                    <Card title="Needs attention">
                        <EmptyState
                            v-if="needsAttention.length === 0"
                            title="Nothing is in the way"
                            description="No deal is blocked and nothing is late. This panel fills itself when that changes."
                        />
                        <ul v-else class="flex flex-col">
                            <li
                                v-for="deal in needsAttention"
                                :key="deal.id"
                                class="flex items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <TextLink
                                        :href="deal.url"
                                        class="truncate text-13 font-medium"
                                        >{{ deal.name }}</TextLink
                                    >
                                    <span
                                        v-if="deal.stageName"
                                        class="truncate text-[11px] text-muted-foreground"
                                        >{{ deal.stageName }}</span
                                    >
                                </span>
                                <StatusBadge
                                    v-if="deal.isBlocked"
                                    tone="warning"
                                    label="Blocked"
                                    dotless
                                />
                                <StatusBadge
                                    v-if="(deal.overdueCount ?? 0) > 0"
                                    tone="danger"
                                    :label="`${deal.overdueCount} overdue`"
                                    dotless
                                />
                            </li>
                        </ul>
                    </Card>

                    <Card title="Active deals" class="flex-1">
                        <template #action>
                            <TextLink href="/deals">All deals</TextLink>
                        </template>
                        <ul class="flex flex-col">
                            <li
                                v-for="deal in deals"
                                :key="deal.id"
                                class="flex items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <TextLink
                                        :href="deal.url"
                                        class="truncate text-13 font-medium"
                                        >{{ deal.name }}</TextLink
                                    >
                                    <span
                                        v-if="deal.stageName"
                                        class="truncate text-[11px] text-muted-foreground"
                                        >{{ deal.stageName }}</span
                                    >
                                </span>
                                <StatusBadge
                                    v-if="deal.stageState"
                                    domain="stage"
                                    :state="deal.stageState"
                                />
                            </li>
                        </ul>
                    </Card>
                </div>

                <div class="flex flex-col gap-6 lg:w-[352px]">
                    <Card title="Dates &amp; Deadlines">
                        <EmptyState
                            v-if="dueSoon.length === 0"
                            title="Nothing due this fortnight"
                            description="Deals with something due in the next 14 days show up here."
                        />
                        <ul v-else class="flex flex-col">
                            <li
                                v-for="deal in dueSoon"
                                :key="deal.id"
                                class="flex items-center gap-3 border-b px-4 py-2.5 last:border-b-0"
                            >
                                <TextLink
                                    :href="deal.url"
                                    class="min-w-0 flex-1 truncate text-13"
                                    >{{ deal.name }}</TextLink
                                >
                                <span
                                    v-if="deal.nextDueDate"
                                    class="shrink-0 text-[11px] text-muted-foreground"
                                    >{{
                                        formatDateShort(deal.nextDueDate)
                                    }}</span
                                >
                            </li>
                        </ul>
                    </Card>

                    <Card title="Activity" class="flex-1">
                        <template #action>
                            <TextLink href="/activity">All activity</TextLink>
                        </template>
                        <EmptyState
                            v-if="entries.length === 0"
                            title="Nothing has happened yet"
                            description="Everything anybody does shows up here, newest first."
                        />
                        <ol v-else class="flex flex-col divide-y">
                            <li
                                v-for="entry in entries"
                                :key="entry.event.id"
                                class="px-4"
                            >
                                <ActivityItem
                                    :icon="entry.icon"
                                    :tone="entry.tone"
                                    :text="entry.event.summary"
                                    :time="entry.event.occurredAt"
                                />
                            </li>
                        </ol>
                    </Card>
                </div>
            </div>
        </template>
    </div>
</template>
