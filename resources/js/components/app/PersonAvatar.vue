<script setup lang="ts">
/**
 * Design System §7.2. Initials in the accent circle; sizes are the ones the
 * designs actually use, so a screen picks a size rather than inventing one.
 */
import { computed } from 'vue';
import { personInitials } from '@/lib/formatters';
import type { NameParts } from '@/lib/formatters';
import { cn } from '@/lib/utils';

type Props = {
    person: NameParts & { name?: string | null };
    size?: 20 | 24 | 26 | 30 | 32 | 46;
    /** Client surfaces fill the avatar with the team's brand accent. */
    brand?: boolean;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), { size: 24, brand: false });

const initials = computed(() => {
    if (props.person.firstName || props.person.lastName) {
        return personInitials(props.person);
    }

    const [first, last] = (props.person.name ?? '').split(' ');

    return personInitials({ firstName: first, lastName: last });
});

const TEXT: Record<NonNullable<Props['size']>, string> = {
    20: 'text-[9px]',
    24: 'text-[10px]',
    26: 'text-[10px]',
    30: 'text-[11px]',
    32: 'text-xs',
    46: 'text-[17px]',
};
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center justify-center rounded-full font-semibold',
                brand
                    ? 'bg-brand text-brand-foreground'
                    : 'bg-accent text-primary',
                TEXT[size],
                props.class,
            )
        "
        :style="{ width: `${size}px`, height: `${size}px` }"
        data-slot="person-avatar"
        aria-hidden="true"
    >
        {{ initials }}
    </span>
</template>
