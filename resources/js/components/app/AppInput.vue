<script setup lang="ts">
/**
 * A form control at the measured size — Design System §4.2: 40px on a pointer
 * device, 44px on a phone (§11), `px-3`, 14px type. Never `text-13`, which is
 * for rows rather than for anything a person types into (§3.3).
 *
 * Two things worth knowing:
 *
 *  - `size` here is the design-system size, not HTML's `size` attribute. The
 *    prop consumes the name, so the native one cannot be set — which is fine,
 *    since a width belongs in CSS, but it is worth saying out loud.
 *  - This is `<input>` only. A textarea is `rounded-md border p-[11px]` at
 *    13–14px (§10) and gets its own component when the first form needs one.
 */
import { cn } from '@/lib/utils';
import { appInputVariants } from './controlVariants';
import type { AppInputVariants } from './controlVariants';

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        size?: AppInputVariants['size'];
        type?: string;
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
