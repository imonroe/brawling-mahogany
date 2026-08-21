<script setup lang="ts">
/**
 * Design System §7.2. 32×32, 18px icon, optional unread dot.
 * The icon alone never carries meaning — pass a label for screen readers.
 */
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

type Props = {
    icon: Component;
    label: string;
    unread?: boolean;
    class?: string;
};

const props = defineProps<Props>();
</script>

<template>
    <button
        type="button"
        :aria-label="label"
        :class="
            cn(
                'relative inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 ease-out hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                props.class,
            )
        "
        data-slot="icon-button"
    >
        <component :is="icon" class="size-[18px]" :stroke-width="2" aria-hidden="true" />
        <span
            v-if="unread"
            class="absolute top-[5px] right-[5px] size-2 rounded-full bg-destructive ring-2 ring-background"
            aria-hidden="true"
        />
    </button>
</template>
