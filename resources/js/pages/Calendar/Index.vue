<script setup lang="ts">
/**
 * S57 — the calendar (PRD §4.8 F8.1 · issue #105).
 *
 * ## Two kinds of thing, one grid
 *
 * Screen Inventory: *"events and deadlines are different things sharing a
 * grid."* An event is a block of time somebody attends; a deadline is a moment
 * with legal consequences that nobody attends. `CalendarItem` carries the
 * distinction in shape, order and words rather than in colour alone, and the
 * ordering is decided on the server because it is a statement about which
 * matters.
 *
 * ## The view lives in the URL
 *
 * Month, week and agenda are three `GET`s rather than three client-side
 * filters, because each needs a different window and the window is what the
 * query narrows on. It also means next month is a link somebody can bookmark,
 * and that the back button does what a person expects on a screen they page
 * through.
 *
 * ## Times are the team's, everywhere
 *
 * PRD §9. `formatters.ts` is already told the team's zone by the shell, and
 * every instant on this page arrives pre-converted from `CalendarBoard` — so a
 * colleague reading this from an airport sees the closing at the hour the
 * closing is, which is the whole point of the rule.
 */
import { Head, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Plus,
    Rss,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/app/AppButton.vue';
import CalendarFeedDialog from '@/components/app/CalendarFeedDialog.vue';
import type { CalendarFeedRow } from '@/components/app/CalendarFeedDialog.vue';
import CalendarItem from '@/components/app/CalendarItem.vue';
import type { CalendarItemRow } from '@/components/app/CalendarItem.vue';
import CalendarMonth from '@/components/app/CalendarMonth.vue';
import Card from '@/components/app/Card.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import EventFormDialog from '@/components/app/EventFormDialog.vue';
import type { EventFormValues } from '@/components/app/EventFormDialog.vue';
import IconButton from '@/components/app/IconButton.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import SegmentedControl from '@/components/app/SegmentedControl.vue';
import { usePermissions } from '@/composables/usePermissions';
import { shiftFocus } from '@/lib/calendarNavigation';
import { formatDate, formatDateShort, isoDateIn } from '@/lib/formatters';

const props = defineProps<{
    view: 'month' | 'week' | 'agenda';
    focus: string;
    timezone: string;
    range: { from: string; to: string };
    items: CalendarItemRow[];
    /**
     * The editable form of every event in the window, keyed by id.
     *
     * Separate from `items` because the two are different shapes for different
     * jobs: an item is one **occurrence** on one square (a weekly series
     * produces four of them), and the form edits the **series**. Sending the
     * form values with the grid rather than fetching them when the dialog
     * opens keeps a modal somebody opens ten times a morning instant.
     */
    editableEvents: EventFormValues[];
    eventTypes: Record<string, string>;
    /** S60's list (#108) — this person's own feeds, never a colleague's. */
    feeds: CalendarFeedRow[];
    dealOptions: { id: string; label: string }[];
    attendeeOptions: { id: string; name: string }[];
}>();

const { can } = usePermissions();

/** PRD §4.2 F2.2's Read Only role reads the week and moves nothing. */
const canManage = computed(() => can('deals.manage'));

const dialogOpen = ref(false);
const feedsOpen = ref(false);
const editing = ref<EventFormValues | null>(null);
const addingOn = ref<string | null>(null);

/*
 * The **team's** today, not the browser's UTC one. `toISOString()` is UTC by
 * definition, so from 18:00 Denver onwards it returns tomorrow — the today
 * ring landed on the wrong square, the agenda emphasised the wrong day and the
 * Today button navigated to it, for the last third of every working day west
 * of UTC. The controller has been shipping `timezone` for exactly this and
 * nothing read it.
 *
 * `isoDateIn` is the same function `calendarDaysBetween` uses, which is what
 * keeps this screen and every date-distance question in the product answering
 * in one calendar.
 */
const today = computed(() => isoDateIn(new Date(), props.timezone));

const events = computed(
    () => new Map(props.editableEvents.map((event) => [event.id, event])),
);

const segments = [
    { value: 'month', label: 'Month' },
    { value: 'week', label: 'Week' },
    { value: 'agenda', label: 'Agenda' },
];

/**
 * The heading, which says which window is on screen.
 *
 * Design System §8.5: the subtitle carries *"the temporal context that makes
 * a screen legible at a glance"*, and on a calendar that context is the whole
 * of what the screen is showing.
 */
const windowLabel = computed(() => {
    if (props.view === 'month') {
        return new Intl.DateTimeFormat('en-US', {
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(new Date(`${props.focus}T12:00:00Z`));
    }

    return `${formatDateShort(props.range.from)} – ${formatDateShort(props.range.to)}`;
});

/**
 * One press of an arrow.
 *
 * The arithmetic is `lib/calendarNavigation.ts` rather than a closure here,
 * so the test that holds it imports the function this calls instead of a copy
 * of it — the first version of that test passed with the fix deleted.
 */
function shift(direction: -1 | 1): void {
    go(props.view, shiftFocus(props.focus, direction, props.view));
}

function go(view: string, date: string): void {
    router.get('/calendar', { view, date }, { preserveScroll: true });
}

/**
 * The agenda, grouped by day.
 *
 * The server already returns the list in day-then-time order, so this is a
 * fold rather than a sort — a second ordering here would be a second opinion
 * about whether a deadline sits above a 4pm showing.
 */
const agendaDays = computed(() => {
    const groups: { day: string; items: CalendarItemRow[] }[] = [];

    for (const item of props.items) {
        const last = groups.at(-1);

        if (last && last.day === item.day) {
            last.items.push(item);
        } else {
            groups.push({ day: item.day, items: [item] });
        }
    }

    return groups;
});

/**
 * What a click on the grid does, per kind.
 *
 * An **event** opens S58 over this page. A **deadline** is a visit to the
 * deal's Dates & Deadlines, because that is where its anchor, its cascade and
 * its reminders live — editing one from a calendar cell would be editing a
 * contingency chain through a keyhole.
 */
function select(item: CalendarItemRow): void {
    if (item.kind === 'deadline') {
        if (item.deal) {
            router.visit(item.deal.url);
        }

        return;
    }

    const source = events.value.get(item.id);

    if (!source || !canManage.value) {
        return;
    }

    editing.value = source;
    addingOn.value = null;
    dialogOpen.value = true;
}

function openAdd(day: string | null = null): void {
    editing.value = null;
    addingOn.value = day;
    dialogOpen.value = true;
}

function remove(id: string): void {
    if (
        !window.confirm(
            'Remove this event? It comes off the calendar for everyone.',
        )
    ) {
        return;
    }

    router.delete(`/calendar/events/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Calendar" />

    <div class="flex flex-col gap-4">
        <PageHeader title="Calendar" :subtitle="windowLabel">
            <template #actions>
                <IconButton
                    :icon="ChevronLeft"
                    label="Previous"
                    @click="shift(-1)"
                />
                <AppButton variant="ghost" @click="go(view, today)"
                    >Today</AppButton
                >
                <IconButton
                    :icon="ChevronRight"
                    label="Next"
                    @click="shift(1)"
                />
                <!--
                    S60 (#108). A read, not a write, so it is offered to
                    anybody who can see the calendar — PRD §4.2 F2.2's Read
                    Only role has as much reason to subscribe as anybody.
                -->
                <AppButton variant="ghost" @click="feedsOpen = true">
                    <Rss class="size-4" />
                    Subscribe
                </AppButton>
                <AppButton v-if="canManage" @click="openAdd()">
                    <Plus class="size-4" />
                    Add event
                </AppButton>
            </template>
        </PageHeader>

        <SegmentedControl
            :model-value="view"
            :segments="segments"
            @update:model-value="go($event, focus)"
        />

        <Card v-if="view === 'month'" class="overflow-hidden p-0">
            <CalendarMonth
                :range="range"
                :focus="focus"
                :today="today"
                :items="items"
                @open-day="go('agenda', $event)"
                @select="select"
            />
        </Card>

        <Card v-else class="p-0">
            <EmptyState
                v-if="agendaDays.length === 0"
                :icon="CalendarDays"
                title="Nothing in this window"
                description="Showings, inspections and closings appear here, alongside the deadlines they hang off."
            />

            <div v-else class="divide-y">
                <div
                    v-for="group in agendaDays"
                    :key="group.day"
                    class="flex flex-col gap-1.5 p-3 sm:flex-row sm:gap-4"
                >
                    <div class="w-40 shrink-0">
                        <p
                            class="text-sm font-semibold"
                            :class="
                                group.day === today
                                    ? 'text-primary'
                                    : 'text-foreground'
                            "
                        >
                            {{ formatDate(group.day) }}
                        </p>
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <CalendarItem
                            v-for="item in group.items"
                            :key="item.key"
                            :item="item"
                            :today="today"
                            show-deal
                            @select="select"
                        />
                    </div>
                </div>
            </div>
        </Card>

        <CalendarFeedDialog
            v-model:open="feedsOpen"
            :feeds="feeds"
            :deal-options="dealOptions"
        />

        <EventFormDialog
            v-if="canManage"
            v-model:open="dialogOpen"
            :event="editing"
            :default-day="addingOn"
            :timezone="timezone"
            :event-types="eventTypes"
            :deal-options="dealOptions"
            :attendee-options="attendeeOptions"
            :return-view="view"
            :return-date="focus"
            @delete="remove"
        />
    </div>
</template>
