<script setup lang="ts">
/**
 * Design System §8.2, top band of the sidebar. Hidden when the person belongs
 * to a single team (Screen Inventory S09) — a switcher with one option is a
 * decision nobody asked to make.
 */
import { ChevronsUpDown } from '@lucide/vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        name: string;
        plan?: string | null;
        mark?: string;
        switchable?: boolean;
        collapsed?: boolean;
    }>(),
    { switchable: false, collapsed: false, plan: null },
);
</script>

<template>
    <component
        :is="switchable ? 'button' : 'div'"
        :type="switchable ? 'button' : undefined"
        :class="
            cn(
                'flex h-14 w-full items-center gap-[9px] border-b px-3 text-left',
                switchable &&
                    'transition-colors duration-150 ease-out hover:bg-accent/60',
            )
        "
        data-slot="team-switcher"
    >
        <span
            class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary text-[11px] font-bold text-primary-foreground"
            aria-hidden="true"
            >{{ mark ?? name.slice(0, 2).toUpperCase() }}</span
        >
        <span v-if="!collapsed" class="flex min-w-0 flex-1 flex-col">
            <span class="truncate text-sm font-semibold text-foreground">{{
                name
            }}</span>
            <span
                v-if="plan"
                class="truncate text-[11px] text-muted-foreground"
                >{{ plan }}</span
            >
        </span>
        <ChevronsUpDown
            v-if="switchable && !collapsed"
            class="size-3.5 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
    </component>
</template>
