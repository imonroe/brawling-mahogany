<script setup lang="ts">
/** Design System §8.6. Active chips tint border, background, and both texts. */
import { ChevronDown } from '@lucide/vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    filterKey: string;
    value: string;
    active?: boolean;
    class?: string;
}>();
</script>

<template>
    <button
        type="button"
        :class="
            cn(
                'flex h-8 items-center gap-1.5 rounded-md border px-2.5 transition-colors duration-150 ease-out',
                props.active ? 'border-primary bg-accent' : 'border-border',
                props.class,
            )
        "
        data-slot="filter-chip"
    >
        <span
            :class="
                cn(
                    'text-xs',
                    props.active ? 'text-primary' : 'text-muted-foreground',
                )
            "
            >{{ filterKey }}:</span
        >
        <span
            :class="
                cn(
                    'text-xs font-semibold',
                    props.active ? 'text-primary' : 'text-foreground',
                )
            "
            >{{ value }}</span
        >
        <ChevronDown
            :class="
                cn(
                    'size-3',
                    props.active ? 'text-primary' : 'text-muted-foreground',
                )
            "
            aria-hidden="true"
        />
    </button>
</template>
