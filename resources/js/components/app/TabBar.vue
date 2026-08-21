<script setup lang="ts">
/** Tabs sit in a row with gap-[22px]; the container carries the border. */
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: string; label?: string }>();
</script>

<template>
    <nav
        :class="cn('flex items-center gap-[22px] border-b', props.class)"
        :aria-label="label ?? 'Tabs'"
        data-slot="tab-bar"
    >
        <slot />
    </nav>
</template>
