<script setup lang="ts">
/**
 * The multi-line field, at Design System §10's measurement.
 *
 * §10, in full: *"Textarea: `rounded-md border p-[11px]` at 13–14px, roughly
 * 86px tall for a reason field."* The reason field it names is S24's, which is
 * the first form in the product that needed one — §7.5's note that a textarea
 * *"gets its own component when the first form needs one"* is this.
 *
 * The padding is `p-[11px]` rather than the input's `px-3`, and that is the
 * spec rather than an approximation: a single-line control centres its text
 * vertically and a block of prose does not, so the horizontal and vertical
 * padding match here where they cannot on an input.
 *
 * 16px below `md` for the same reason `AppInput` carries it — iOS Safari zooms
 * the page when a field under 16px takes focus — and 14px above it. Never
 * `text-13`, which §3.3 reserves for rows rather than for anything a person
 * types into.
 */
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        rows?: number;
        invalid?: boolean;
        class?: string;
    }>(),
    { rows: 4, invalid: false },
);

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <textarea
        :value="modelValue ?? ''"
        :rows="props.rows"
        :aria-invalid="props.invalid || undefined"
        :class="
            cn(
                'flex min-h-[86px] w-full rounded-md border bg-background p-[11px] text-base text-foreground transition-colors duration-150 ease-out placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm',
                props.class,
            )
        "
        data-slot="app-textarea"
        @input="
            $emit(
                'update:modelValue',
                ($event.target as HTMLTextAreaElement).value,
            )
        "
    />
</template>
