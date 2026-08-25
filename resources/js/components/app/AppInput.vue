<script setup lang="ts">
/**
 * A form control at the measured size — Design System §4.2: 40px on a pointer
 * device, 44px on a phone (§11), `px-3`. The type is 14px above `md` (12px for
 * the filter size) and 16px below it, because iOS Safari zooms the page when a
 * field under 16px takes focus. Never `text-13`, which is for rows rather than
 * for anything a person types into (§3.3).
 *
 * Two things worth knowing:
 *
 *  - `size` here is the design-system size, not HTML's `size` attribute. The
 *    prop consumes the name, so the native one cannot be set — which is fine,
 *    since a width belongs in CSS, but it is worth saying out loud.
 *  - This is a *text* `<input>` only. It binds `:value` and emits a string,
 *    so `type="checkbox"`/`"radio"` would bind the wrong property entirely
 *    and `type="number"` would hand a string back to the caller; the `type`
 *    prop is narrowed to the text-like set rather than left to a comment. A
 *    textarea is `rounded-md border p-[11px]` at 13–14px (§10) and gets its
 *    own component when the first form needs one.
 *
 *    `datetime-local` is in that set for the same reason the rest of it is:
 *    it binds `value` and emits a string. What it does *not* carry is a
 *    timezone — a browser puts wall-clock time in one — so whatever reads it
 *    has to say which zone that is (PRD §9 stores UTC and displays the
 *    team's). `ContactLogController` is the worked example.
 *
 *    `date` is the same shape with the timezone question settled rather than
 *    deferred: it emits `YYYY-MM-DD`, which is a day rather than an instant,
 *    and `tasks.due_date` is a `date` column for exactly that reason (S27,
 *    #71). A deadline is a day in the team's calendar, not a moment in UTC.
 */
import { cn } from '@/lib/utils';
import { appInputVariants } from './controlVariants';
import type { AppInputVariants } from './controlVariants';

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        size?: AppInputVariants['size'];
        type?:
            | 'text'
            | 'email'
            | 'password'
            | 'search'
            | 'tel'
            | 'url'
            | 'date'
            | 'datetime-local';
        class?: string;
    }>(),
    { size: 'default', type: 'text' },
);

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <input
        :type="props.type"
        :value="modelValue"
        :class="cn(appInputVariants({ size: props.size }), props.class)"
        data-slot="app-input"
        @input="
            $emit(
                'update:modelValue',
                ($event.target as HTMLInputElement).value,
            )
        "
    />
</template>
