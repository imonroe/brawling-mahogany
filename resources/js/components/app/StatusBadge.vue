<script setup lang="ts">
/**
 * The most-used component in the product (Design System §7.2).
 *
 * Dot plus word, always both, never the dot alone — a colour on its own is
 * not a status. Tone drives three properties together: the container
 * background, the dot, and the label. They never mix.
 *
 * Prefer the `domain` + `state` form, which resolves the label and the tone
 * from the one state table (lib/states.ts). The bare `tone` form exists for
 * counts and one-off pills.
 */
import { computed } from 'vue';
import { resolveState } from '@/lib/states';
import type { StateDescriptor, StateDomain, Tone } from '@/lib/states';
import { cn } from '@/lib/utils';

type Props = {
    domain?: StateDomain;
    state?: string;
    tone?: Tone;
    /** Overrides the label from the state table. */
    label?: string;
    /**
     * Counts and terminal statuses drop the dot but keep the pill and the
     * colour: header counts, "Met", "Confirmed" (Design System §7.2).
     */
    dotless?: boolean;
    class?: string;
};

const props = defineProps<Props>();

/*
 * Resolved per render, not once in setup(): Vue reuses component instances
 * when a keyed list re-renders with changed data, so a badge resolved once
 * would keep rendering the previous row's state and colour after a filter,
 * a page change, or a partial reload.
 */
const descriptor = computed<StateDescriptor | null>(() => {
    if (!props.domain || !props.state) {
        if (!props.tone) {
            throw new Error(
                'StatusBadge needs either a `domain` and `state`, or a `tone`.',
            );
        }

        return null;
    }

    try {
        return resolveState(props.domain, props.state);
    } catch (error) {
        /*
         * An unknown state is a bug and it should be loud — but not so loud
         * that it takes the whole page down in front of a customer. It throws
         * in development and in tests; in production it reports and renders a
         * neutral badge carrying the raw code, which is ugly enough to get
         * fixed and small enough not to lose the screen.
         */
        if (import.meta.env.DEV) {
            throw error;
        }

        console.error(error);

        return { label: props.state, tone: 'neutral', clientLabel: null };
    }
});

const tone = computed<Tone>(
    () => props.tone ?? descriptor.value?.tone ?? 'neutral',
);
const label = computed(() => props.label ?? descriptor.value?.label ?? '');

const TONE_CLASSES: Record<Tone, string> = {
    neutral: 'bg-state-neutral-bg text-state-neutral',
    info: 'bg-state-info-bg text-state-info',
    success: 'bg-state-success-bg text-state-success',
    warning: 'bg-state-warning-bg text-state-warning',
    danger: 'bg-state-danger-bg text-state-danger',
};

const DOT_CLASSES: Record<Tone, string> = {
    neutral: 'bg-state-neutral',
    info: 'bg-state-info',
    success: 'bg-state-success',
    warning: 'bg-state-warning',
    danger: 'bg-state-danger',
};
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-[3px] text-xs font-medium',
                TONE_CLASSES[tone],
                props.class,
            )
        "
        data-slot="status-badge"
        :data-tone="tone"
    >
        <span
            v-if="!dotless"
            :class="cn('size-1.5 rounded-full', DOT_CLASSES[tone])"
        />
        <slot>{{ label }}</slot>
    </span>
</template>
