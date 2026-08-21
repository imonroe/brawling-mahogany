<script setup lang="ts">
/** Design System §8.6. Used on My Work; zero gap, borders between segments. */
import { cn } from '@/lib/utils';

type Segment = { value: string; label: string; count?: number | null };

const props = defineProps<{ modelValue: string; segments: Segment[]; class?: string }>();
defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div
        :class="cn('flex overflow-hidden rounded-md border', props.class)"
        role="tablist"
        data-slot="segmented-control"
    >
        <button
            v-for="segment in segments"
            :key="segment.value"
            type="button"
            role="tab"
            :aria-selected="segment.value === modelValue"
            :class="
                cn(
                    'flex h-8 items-center gap-1.5 border-r px-3 text-xs last:border-r-0',
                    segment.value === modelValue
                        ? 'bg-accent font-semibold text-primary'
                        : 'font-medium text-muted-foreground',
                )
            "
            @click="$emit('update:modelValue', segment.value)"
        >
            {{ segment.label }}
            <span v-if="segment.count !== undefined && segment.count !== null" class="tabular">{{
                segment.count
            }}</span>
        </button>
    </div>
</template>
