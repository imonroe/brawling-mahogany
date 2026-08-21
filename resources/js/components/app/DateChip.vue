<script setup lang="ts">
/**
 * Design System §7.2. Tone follows urgency, not stage state: neutral
 * normally, danger when the date is overdue or due today. Background, icon,
 * and text move together.
 */
import { Calendar } from '@lucide/vue';
import { computed } from 'vue';
import {
    calendarDaysBetween,
    formatDateShort,
    formatRelativeDate,
} from '@/lib/formatters';
import { cn } from '@/lib/utils';

type Props = {
    date: string | number | Date;
    /** Relative inside seven days, absolute beyond it (IA §10). */
    relative?: boolean;
    /** Overrides the urgency calculation — a past completion is not overdue. */
    tone?: 'neutral' | 'danger';
    now?: string | number | Date;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), { relative: false });

const tone = computed(() => {
    if (props.tone) {
        return props.tone;
    }

    return calendarDaysBetween(props.now ?? new Date(), props.date) <= 0
        ? 'danger'
        : 'neutral';
});

const label = computed(() =>
    props.relative
        ? formatRelativeDate(
              props.date,
              props.now ? { now: props.now } : undefined,
          )
        : formatDateShort(props.date),
);
</script>

<template>
    <span
        :class="
            cn(
                'tabular inline-flex items-center gap-[5px] rounded-sm px-[7px] py-[3px] text-xs font-medium',
                tone === 'danger'
                    ? 'bg-state-danger-bg text-state-danger'
                    : 'bg-state-neutral-bg text-state-neutral',
                props.class,
            )
        "
        data-slot="date-chip"
    >
        <Calendar class="size-3" :stroke-width="2" aria-hidden="true" />
        {{ label }}
    </span>
</template>
