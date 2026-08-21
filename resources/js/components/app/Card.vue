<script setup lang="ts">
/**
 * The §8.7 chrome card: a bordered box with a header band and rows, used for
 * dashboard panels, deal panels, and every table card.
 *
 * This is not `@/components/ui/card` — that is the shadcn primitive, with its
 * own padding and radius. This one carries the measured header band
 * (`px-4 py-[13px]`), the 12px/500 header link, and `overflow-hidden` so the
 * last row's border is closed by the card itself.
 */
import { cn } from '@/lib/utils';

const props = defineProps<{ title?: string; class?: string; bodyClass?: string }>();
</script>

<template>
    <section
        :class="cn('flex flex-col overflow-hidden rounded-lg border bg-card', props.class)"
        data-slot="card"
    >
        <header
            v-if="title || $slots.header || $slots.badge || $slots.action"
            class="flex items-center gap-2 border-b px-4 py-[13px]"
        >
            <slot name="header">
                <h3 class="text-13 font-semibold text-card-foreground">{{ title }}</h3>
            </slot>
            <slot name="badge" />
            <div class="flex-1"></div>
            <slot name="action" />
        </header>
        <div :class="cn('flex flex-col', props.bodyClass)">
            <slot />
        </div>
    </section>
</template>
