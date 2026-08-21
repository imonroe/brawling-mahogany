<script setup lang="ts">
/**
 * A form control at the measured size — Design System §4.2: 40px, `px-3`,
 * 14px type. Never `text-13`, which is for rows rather than for anything a
 * person types into (§3.3).
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
