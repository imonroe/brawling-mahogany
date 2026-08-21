<script setup lang="ts">
/**
 * Design System §7.2. The active indicator is a 2px bottom border on the tab
 * itself, not a separate element.
 */
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

type Props = {
    label: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    count?: number | null;
    active?: boolean;
};

withDefaults(defineProps<Props>(), { active: false, count: null });
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        :href="href"
        :type="href ? undefined : 'button'"
        :aria-current="active ? 'page' : undefined"
        :class="
            cn(
                'flex h-[38px] items-center border-b-2 transition-colors duration-150 ease-out',
                active ? 'border-primary' : 'border-transparent',
            )
        "
        data-slot="tab"
    >
        <span class="flex items-center gap-1.5 px-[3px]">
            <span
                :class="
                    cn(
                        'text-sm',
                        active ? 'font-semibold text-foreground' : 'font-medium text-muted-foreground',
                    )
                "
                >{{ label }}</span
            >
            <span
                v-if="count !== null"
                class="rounded-full bg-muted px-1.5 py-px text-[11px] font-medium text-muted-foreground"
                >{{ count }}</span
            >
        </span>
    </component>
</template>
