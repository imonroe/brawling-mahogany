<script setup lang="ts">
/**
 * Design System §9.3's stat card, for the dashboard's four fixed numbers.
 *
 * *"`p-4 gap-2 rounded-lg border bg-card`, containing `[label 12/500 muted]
 * [flex-1] [icon 14]`, then the value at 26px/600, then a 12px delta line
 * tinted by the metric's own state."*
 *
 * The tone is the metric's, not the value's: **Blocked stages** is amber
 * whether it reads 0 or 9, because §2.4 gives the colour to the kind of thing
 * being counted rather than to how alarming today's number is. A zero that
 * turns green would make the row flicker between palettes as work moves.
 */
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import type { Tone } from '@/lib/states';

const props = withDefaults(
    defineProps<{
        label: string;
        value: number;
        icon: LucideIcon;
        /** The delta or qualifier beneath the number. */
        note?: string | null;
        /** Neutral unless the metric itself is a warning or a danger. */
        tone?: Tone;
    }>(),
    { note: null, tone: 'neutral' },
);

/**
 * A tone becomes a colour only when the number is not zero.
 *
 * Nothing blocked is not a warning, and painting a `0` amber is the screen
 * raising an alarm about the absence of a problem — which is how a dashboard
 * teaches somebody to stop reading it.
 */
const noteClass = computed(() => {
    if (props.value === 0 || props.tone === 'neutral') {
        return 'text-muted-foreground';
    }

    return props.tone === 'danger' ? 'text-state-danger' : 'text-state-warning';
});
</script>

<template>
    <div
        class="flex flex-1 flex-col gap-2 rounded-lg border bg-card p-4"
        data-slot="stat-card"
    >
        <div class="flex items-center gap-2">
            <span class="text-xs font-medium text-muted-foreground">{{
                label
            }}</span>
            <span class="flex-1" />
            <component
                :is="icon"
                class="size-[14px] shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </div>

        <span class="text-[26px] leading-none font-semibold text-foreground">{{
            value
        }}</span>

        <span v-if="note" :class="['text-xs', noteClass]">{{ note }}</span>
    </div>
</template>
