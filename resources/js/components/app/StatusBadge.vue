<script setup lang="ts">
/**
 * The most-used component in the product (Design System §7.2).
 *
 * Dot plus word, always both, never the dot alone — a colour on its own is
 * not a status. Tone drives three properties together: the container
 * background, the dot, and the label. They never mix.
 *
 * Prefer the `domain` + `state` form, which resolves the label and the tone
 * from the one state table (lib/states.ts) and throws on an unknown state.
 * The bare `tone` form exists for counts and one-off pills.
 */
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { resolveState, type StateDomain, type Tone } from '@/lib/states';

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

// Resolved eagerly rather than lazily: an unknown state must fail where it is
// written, not silently render an unstyled badge somewhere downstream.
const descriptor =
    props.domain && props.state ? resolveState(props.domain, props.state) : null;

if (!descriptor && !props.tone) {
    throw new Error('StatusBadge needs either a `domain` and `state`, or a `tone`.');
}

const tone = computed<Tone>(() => props.tone ?? descriptor!.tone);
const label = computed(() => props.label ?? descriptor?.label ?? '');

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
        <span v-if="!dotless" :class="cn('size-1.5 rounded-full', DOT_CLASSES[tone])" />
        <slot>{{ label }}</slot>
    </span>
</template>
