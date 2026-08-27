<script setup lang="ts">
/**
 * The month grid, built by hand (S57 · #105 · Design System §15.3).
 *
 * §15.3 left the library question open — *"most calendar libraries bring heavy
 * styling opinions that will fight this system"* — and the deciding argument
 * turned out not to be the styling. It is that no library models two kinds of
 * thing on one square, which is the one thing this screen exists to express.
 * Adopting one would mean fighting its cell renderer as well as its CSS.
 *
 * What is left is six rows of seven cells over a range the server already
 * computed, which is a smaller thing to own than an adapter.
 *
 * ## A dense day truncates and says how many it hid
 *
 * Screen Inventory lists *dense day* as a key state. A cell that grows to fit
 * eleven items makes the row eleven items tall and pushes the rest of the
 * month off the screen — so the cell shows a fixed few and a *"+4 more"* that
 * opens the day. Silently dropping the rest would be the version of this that
 * looks fine and loses a closing.
 */
import { computed } from 'vue';
import CalendarItem from '@/components/app/CalendarItem.vue';
import type { CalendarItemRow } from '@/components/app/CalendarItem.vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    /** The first and last day the grid draws, inclusive, as YYYY-MM-DD. */
    range: { from: string; to: string };
    /** The month in focus — days outside it are dimmed, not hidden. */
    focus: string;
    today: string;
    items: CalendarItemRow[];
}>();

const emit = defineEmits<{
    openDay: [day: string];
    select: [item: CalendarItemRow];
}>();

/** How many items a cell shows before it starts counting. */
const CELL_LIMIT = 3;

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/**
 * Every day between the two ends, walked as strings.
 *
 * Deliberately not `new Date()` arithmetic: the range is a wall calendar in
 * the team's zone and the browser's zone may be another one, so constructing
 * dates from these strings and adding 86,400,000 would drift across a DST
 * boundary and produce a grid with a repeated or missing day.
 */
function eachDay(from: string, to: string): string[] {
    const days: string[] = [];

    // Parsed as UTC noon: the arithmetic is then whole days, and no timezone
    // the browser might be in can push it onto the day before.
    let cursor = Date.parse(`${from}T12:00:00Z`);
    const end = Date.parse(`${to}T12:00:00Z`);

    while (cursor <= end && days.length < 100) {
        days.push(new Date(cursor).toISOString().slice(0, 10));
        cursor += 86_400_000;
    }

    return days;
}

const byDay = computed(() => {
    const map = new Map<string, CalendarItemRow[]>();

    for (const item of props.items) {
        const bucket = map.get(item.day);

        if (bucket) {
            bucket.push(item);
        } else {
            map.set(item.day, [item]);
        }
    }

    return map;
});

const weeks = computed(() => {
    const days = eachDay(props.range.from, props.range.to);
    const focusMonth = props.focus.slice(0, 7);

    const cells = days.map((day) => ({
        day,
        label: Number(day.slice(8, 10)),
        inFocus: day.slice(0, 7) === focusMonth,
        isToday: day === props.today,
        items: byDay.value.get(day) ?? [],
    }));

    const rows: (typeof cells)[] = [];

    for (let index = 0; index < cells.length; index += 7) {
        rows.push(cells.slice(index, index + 7));
    }

    return rows;
});
</script>

<template>
    <div data-slot="calendar-month">
        <div class="grid grid-cols-7 border-b">
            <div
                v-for="weekday in WEEKDAYS"
                :key="weekday"
                class="px-2 py-1.5 text-center text-[11px] font-medium text-muted-foreground"
            >
                {{ weekday }}
            </div>
        </div>

        <div
            v-for="(week, index) in weeks"
            :key="index"
            class="grid grid-cols-7 border-b last:border-b-0"
        >
            <div
                v-for="cell in week"
                :key="cell.day"
                :class="
                    cn(
                        'flex min-h-24 flex-col gap-0.5 border-r p-1 last:border-r-0',
                        cell.inFocus ? 'bg-background' : 'bg-muted/40',
                    )
                "
            >
                <div class="flex items-center justify-between px-0.5">
                    <span
                        :class="
                            cn(
                                'tabular inline-flex size-5 items-center justify-center rounded-full text-[11px]',
                                cell.isToday
                                    ? 'bg-primary font-semibold text-primary-foreground'
                                    : cell.inFocus
                                      ? 'text-foreground'
                                      : 'text-muted-foreground',
                            )
                        "
                        >{{ cell.label }}</span
                    >
                </div>

                <CalendarItem
                    v-for="item in cell.items.slice(0, CELL_LIMIT)"
                    :key="item.key"
                    :item="item"
                    :today="today"
                    @select="emit('select', $event)"
                />

                <button
                    v-if="cell.items.length > CELL_LIMIT"
                    type="button"
                    class="rounded-sm px-1.5 py-0.5 text-left text-[11px] text-muted-foreground hover:bg-accent"
                    @click="emit('openDay', cell.day)"
                >
                    +{{ cell.items.length - CELL_LIMIT }} more
                </button>
            </div>
        </div>
    </div>
</template>
