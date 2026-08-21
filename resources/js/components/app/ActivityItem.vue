<script setup lang="ts">
/**
 * Design System §7.3. Icon circle tinted by event type, text that wraps, and
 * a timestamp that does not.
 */
import type { Component } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { Tone } from '@/lib/states';

const props = withDefaults(
    defineProps<{
        icon: Component;
        text: string;
        time: string;
        /** completion → success, message sent → info, override → warning. */
        tone?: Tone;
        class?: string;
    }>(),
    { tone: 'neutral' },
);

const TONE_TEXT: Record<Tone, string> = {
    neutral: 'text-state-neutral',
    info: 'text-state-info',
    success: 'text-state-success',
    warning: 'text-state-warning',
    danger: 'text-state-danger',
};

const iconClass = computed(() => TONE_TEXT[props.tone]);
</script>

<template>
    <div
        :class="cn('flex items-start gap-2.5 py-2.5', props.class)"
        data-slot="activity-item"
    >
        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted">
            <component
                :is="icon"
                :class="cn('size-3.5', iconClass)"
                :stroke-width="2"
                aria-hidden="true"
            />
        </span>
        <div class="flex min-w-0 flex-1 flex-col">
            <p class="text-sm text-foreground">{{ text }}</p>
            <span class="tabular text-xs whitespace-nowrap text-muted-foreground">{{ time }}</span>
        </div>
    </div>
</template>
