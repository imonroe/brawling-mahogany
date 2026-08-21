<script setup lang="ts">
/**
 * Design System §7.3. h-8, 18px icon, 14/500 label, count on the right.
 * Active: accent background, icon and label in primary, label at 600.
 */
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';
import type { NavEntry } from '@/lib/navigation';

const props = withDefaults(
    defineProps<{
        entry: NavEntry;
        active?: boolean;
        count?: number | null;
        /** The collapsed rail shows icons only, with the label as a title. */
        collapsed?: boolean;
    }>(),
    { active: false, count: null, collapsed: false },
);
</script>

<template>
    <Link
        :href="entry.href"
        :aria-current="active ? 'page' : undefined"
        :title="collapsed ? entry.label : undefined"
        :class="
            cn(
                'flex h-8 items-center gap-2.5 rounded-md px-2.5 py-[7px] transition-colors duration-150 ease-out',
                props.active ? 'bg-accent' : 'hover:bg-accent/60',
                props.collapsed && 'justify-center px-0',
            )
        "
        data-slot="nav-item"
    >
        <component
            :is="entry.icon"
            :class="cn('size-[18px] shrink-0', props.active ? 'text-primary' : 'text-muted-foreground')"
            :stroke-width="2"
            aria-hidden="true"
        />
        <template v-if="!collapsed">
            <span
                :class="
                    cn(
                        'flex-1 truncate text-sm',
                        props.active ? 'font-semibold text-primary' : 'font-medium text-secondary-foreground',
                    )
                "
                >{{ entry.label }}</span
            >
            <span
                v-if="count !== null && count !== undefined"
                class="tabular text-xs text-muted-foreground"
                >{{ count }}</span
            >
        </template>
        <span v-else class="sr-only">{{ entry.label }}</span>
    </Link>
</template>
