<script setup lang="ts">
/**
 * Design System §9.7 and IA §10: say what belongs here, then offer the action
 * that creates it. Never a bare "No results."
 *
 * Two shapes, because the two cases read differently:
 *   - `variant="empty"`   nothing exists yet, and the action creates the first one
 *   - `variant="filtered"` things exist but this filter hides them; the action clears it
 */
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        icon?: Component;
        title: string;
        description?: string | null;
        variant?: 'empty' | 'filtered';
        class?: string;
    }>(),
    { variant: 'empty' },
);
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-1 flex-col items-center justify-center gap-3 px-6 py-12 text-center',
                props.class,
            )
        "
        data-slot="empty-state"
    >
        <component
            :is="icon"
            v-if="icon"
            class="size-6 text-muted-foreground"
            :stroke-width="1.5"
            aria-hidden="true"
        />
        <div class="flex flex-col gap-1">
            <p class="text-sm font-semibold text-foreground">{{ title }}</p>
            <p v-if="description" class="max-w-md text-13 text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.action" class="mt-1 flex items-center gap-2">
            <slot name="action" />
        </div>
    </div>
</template>
