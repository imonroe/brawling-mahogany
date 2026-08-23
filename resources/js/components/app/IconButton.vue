<script setup lang="ts">
/**
 * Design System §7.2. 32×32 on the desktop app, 18px icon, optional unread dot.
 *
 * On a phone it grows to 44px: §11 sets a 44px minimum touch target "without
 * exception", and §4.3 is explicit that the 32px density is a desktop
 * power-user affordance rather than a house style.
 *
 * The icon alone never carries meaning — a label is required, for screen
 * readers and for the pointer tooltip.
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
        :title="label"
        :class="
            cn(
                'relative inline-flex size-11 items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 ease-out hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:size-8',
                props.class,
            )
        "
        data-slot="icon-button"
    >
        <component
            :is="icon"
            class="size-[18px]"
            :stroke-width="2"
            aria-hidden="true"
        />
        <span
            v-if="unread"
            class="absolute top-[11px] right-[11px] size-2 rounded-full bg-destructive ring-2 ring-background md:top-[5px] md:right-[5px]"
            aria-hidden="true"
        />
    </button>
</template>
