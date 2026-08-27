<script setup lang="ts">
/**
 * S59 — every deadline across every deal (PRD §4.8 F8.2 · issue #107).
 *
 * > This is the screen an agent checks on Monday morning to see the week's
 * > exposure across every deal.
 *
 * Which is why **next 14 days** is the default rather than "all": the default
 * has to be the question somebody came here with. Fourteen days matches the
 * dashboard panel (F9.1), so the two never disagree about what *soon* means.
 *
 * ## Critical is a toggle, not a fourth tab
 *
 * It narrows whichever window is showing rather than replacing it. A tab would
 * make *"critical"* and *"overdue"* mutually exclusive, and the row somebody
 * most needs to see is in the overlap.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarClock, Flag } from '@lucide/vue';
import { computed } from 'vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import type { KeyDateRow } from '@/components/app/KeyDateFormDialog.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { formatDate, formatRelativeDate } from '@/lib/formatters';

const props = defineProps<{
    window: 'upcoming' | 'overdue' | 'all';
    criticalOnly: boolean;
    today: string;
    horizonDays: number;
    dates: KeyDateRow[];
    counts: { upcoming: number; overdue: number; critical: number };
}>();

const segments = computed(() => [
    {
        value: 'upcoming',
        label: `Next ${props.horizonDays} days`,
        count: props.counts.upcoming,
    },
    { value: 'overdue', label: 'Past due', count: props.counts.overdue },
    { value: 'all', label: 'All', count: null },
]);

function go(window: string, critical: boolean): void {
    router.get(
        '/dates',
        { window, ...(critical ? { critical: 1 } : {}) },
        { preserveScroll: true },
    );
}

const subtitle = computed(() =>
    props.counts.overdue === 0
        ? 'Nothing is past due.'
        : `${props.counts.overdue} past due`,
);
</script>

<template>
    <Head title="Dates & Deadlines" />

    <div class="flex flex-col gap-4">
        <PageHeader title="Dates &amp; Deadlines" :subtitle="subtitle" />

        <div class="flex flex-wrap items-center gap-2">
            <SegmentedControl
                :model-value="window"
                :segments="segments"
                @update:model-value="go($event, criticalOnly)"
            />

            <label
                class="flex items-center gap-2 text-13 text-muted-foreground"
            >
                <input
                    type="checkbox"
                    :checked="criticalOnly"
                    @change="go(window, !criticalOnly)"
                />
                Critical only ({{ counts.critical }})
            </label>
        </div>

        <Card class="p-0">
            <EmptyState
                v-if="dates.length === 0"
                :icon="CalendarClock"
                :variant="
                    criticalOnly || window !== 'all' ? 'filtered' : 'empty'
                "
                title="Nothing here"
                description="Deadlines you add on a deal’s Dates &amp; Deadlines tab show up here, across every deal."
            />

            <ul v-else class="divide-y">
                <li
                    v-for="date in dates"
                    :key="date.id"
                    class="flex items-start gap-3 p-3"
                >
                    <Flag
                        class="mt-0.5 size-4 shrink-0"
                        :class="
                            date.isPastDue
                                ? 'text-state-danger'
                                : date.isCritical
                                  ? 'text-state-warning'
                                  : 'text-muted-foreground'
                        "
                        :stroke-width="2"
                        aria-hidden="true"
                    />

                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-medium">{{
                                date.name
                            }}</span>
                            <StatusBadge
                                v-if="date.isCritical"
                                tone="warning"
                                label="Critical"
                                dotless
                            />
                            <StatusBadge
                                v-if="date.isPastDue"
                                tone="danger"
                                label="Past due"
                                dotless
                            />
                        </div>

                        <Link
                            v-if="date.deal"
                            :href="date.deal.url"
                            class="truncate text-13 text-primary"
                            >{{ date.deal.label }}</Link
                        >

                        <p
                            v-if="date.derivation"
                            class="text-[11px] text-muted-foreground"
                        >
                            {{ date.derivation }}
                        </p>
                    </div>

                    <div class="tabular shrink-0 text-right">
                        <p class="text-13 font-medium">
                            {{ formatDate(date.date) }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ formatRelativeDate(date.date) }}
                        </p>
                    </div>
                </li>
            </ul>
        </Card>
    </div>
</template>
