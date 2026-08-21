<script setup lang="ts">
/** Design System §8.6. A single h-8 row: search, chips, spacer, view controls. */
import { Search } from '@lucide/vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    modelValue?: string;
    placeholder?: string;
    searchLabel?: string;
    class?: string;
}>();

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div
        :class="cn('flex h-8 items-center gap-2', props.class)"
        data-slot="filter-bar"
    >
        <label
            class="relative flex h-8 w-[260px] items-center gap-2 rounded-md border px-2.5"
        >
            <span class="sr-only">{{ searchLabel ?? 'Search' }}</span>
            <Search class="size-3.5 text-muted-foreground" aria-hidden="true" />
            <input
                type="search"
                :value="modelValue"
                :placeholder="placeholder ?? 'Search'"
                class="h-full flex-1 bg-transparent text-xs text-foreground placeholder:text-muted-foreground focus:outline-none"
                @input="
                    $emit(
                        'update:modelValue',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
        </label>
        <slot />
        <div class="flex-1"></div>
        <slot name="controls" />
    </div>
</template>
