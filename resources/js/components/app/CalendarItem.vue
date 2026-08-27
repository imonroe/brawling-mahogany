<script setup lang="ts">
/**
 * One thing on the calendar — and which of the two things it is (S57 · #105).
 *
 * Screen Inventory calls S57 hard for exactly this: *"events and deadlines are
 * different things sharing a grid."* The distinction has to survive a dense
 * day where five of each land together, so it is carried by **three**
 * properties at once rather than by colour alone:
 *
 *  - **Shape.** A deadline is a flag on a flat row with a left border; an
 *    event is a filled chip with a time. Legible at a glance, and legible in
 *    greyscale — Design System §11 does not let colour be the only channel.
 *  - **Order.** Deadlines sort above events on the same square. That is
 *    decided on the server (`CalendarBoard`), because it is a statement about
 *    which matters rather than about layout.
 *  - **Words.** A deadline shows its name and nothing else. An event shows a
 *    time, because a block of time is a thing somebody has to be somewhere for.
 *
 * A critical deadline takes the warning tone rather than danger, and a past
 * one takes danger. §2.4: danger is for something that has gone wrong, and a
 * deadline that is merely important has not.
 *
 * ## It is always a button, and the page decides where it goes
 *
 * An event opens S58; a deadline goes to the deal's Dates & Deadlines, because
 * that is where the cascade preview and the anchor live. Rendering an `<a>`
 * here would have made the component decide, and the two destinations are not
 * the same kind of thing — one is a dialog over this page and one is a visit.
 */
import { Flag, Repeat } from '@lucide/vue';
import { computed } from 'vue';
import { formatTime } from '@/lib/formatters';
import { cn } from '@/lib/utils';

export type CalendarItemRow = {
    key: string;
    id: string;
    kind: 'event' | 'deadline';
    title: string;
    day: string;
    /** Events only. */
    startsAt?: string;
    endsAt?: string;
    isAllDay?: boolean;
    isRepeat?: boolean;
    /** S58's sentence, composed by the rule — never by this file. */
    repeatSentence?: string | null;
    location?: string | null;
    typeLabel?: string;
    /** Deadlines only. */
    isCritical?: boolean;
    deal: { label: string; url: string } | null;
};

const emit = defineEmits<{ select: [item: CalendarItemRow] }>();

const props = withDefaults(
    defineProps<{
        item: CalendarItemRow;
        /** Today, in the team's zone — the grid already knows it. */
        today: string;
        /** The agenda has room for the deal name; a month cell does not. */
        showDeal?: boolean;
    }>(),
    { showDeal: false },
);

const isPast = computed(
    () => props.item.kind === 'deadline' && props.item.day < props.today,
);

const deadlineTone = computed(() => {
    if (isPast.value) {
        return 'border-l-state-danger bg-state-danger-bg text-state-danger';
    }

    return props.item.isCritical
        ? 'border-l-state-warning bg-state-warning-bg text-state-warning'
        : 'border-l-state-info bg-state-info-bg text-state-info';
});

const time = computed(() => {
    if (
        props.item.kind !== 'event' ||
        props.item.isAllDay ||
        !props.item.startsAt
    ) {
        return null;
    }

    return formatTime(props.item.startsAt);
});
</script>

<template>
    <button
        type="button"
        :class="
            cn(
                'block w-full truncate rounded-sm px-1.5 py-1 text-left text-[11px] font-medium',
                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                item.kind === 'deadline'
                    ? cn('border-l-2', deadlineTone)
                    : 'bg-state-neutral-bg text-foreground hover:bg-accent',
            )
        "
        :data-kind="item.kind"
        data-slot="calendar-item"
        @click="emit('select', item)"
    >
        <span class="flex items-center gap-1">
            <!--
                The flag is the deadline's glyph in the timeline too
                (`lib/activity.ts` gives `milestone.reached` the same one), so
                a reader who has learned it on one screen has learned it here.
            -->
            <Flag
                v-if="item.kind === 'deadline'"
                class="size-3 shrink-0"
                :stroke-width="2"
                aria-hidden="true"
            />
            <span v-else-if="time" class="tabular shrink-0 opacity-70">{{
                time
            }}</span>
            <span class="truncate">{{ item.title }}</span>
            <!--
                A repeating occurrence looks exactly like a one-off without
                this, and the difference decides what editing it does: an edit
                changes the whole series. Labelled rather than left as a glyph,
                because colour and shape alone are not a channel (§11).
            -->
            <Repeat
                v-if="item.isRepeat"
                class="size-3 shrink-0 opacity-60"
                :stroke-width="2"
                aria-hidden="true"
            />
            <span v-if="item.isRepeat" class="sr-only">{{
                item.repeatSentence ?? 'Repeats'
            }}</span>
        </span>
        <span
            v-if="showDeal && item.deal"
            class="block truncate text-[10px] font-normal opacity-70"
            >{{ item.deal.label }}</span
        >
    </button>
</template>
