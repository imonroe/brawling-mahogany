<script setup lang="ts">
/**
 * Design System §8.5. The subtitle is not decoration: it carries the count
 * and the temporal context that makes a screen legible at a glance.
 * One primary action per screen, and it belongs here rather than the top bar.
 */
import { cn } from '@/lib/utils';

const props = defineProps<{ title: string; subtitle?: string | null; class?: string }>();
</script>

<template>
    <header :class="cn('flex items-center gap-3', props.class)" data-slot="page-header">
        <div class="flex min-w-0 flex-col">
            <h1 class="truncate text-xl font-semibold text-foreground">{{ title }}</h1>
            <p v-if="subtitle" class="tabular truncate text-13 text-muted-foreground">
                {{ subtitle }}
            </p>
        </div>
        <div class="flex-1"></div>
        <div class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
