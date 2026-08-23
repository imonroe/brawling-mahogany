<script setup lang="ts">
/**
 * A native `<select>` at the measured control size (Design System §4.2, §11).
 *
 * Not `@/components/ui/select` — that is the shadcn listbox, which is right
 * for a picker with search or rich rows and heavy for "one of four words".
 * This is the plain element, sharing `appInputVariants` with `AppInput` so the
 * two never drift.
 *
 * §13.2 rule 6, applied: a hand-written `h-8 rounded-md border bg-background
 * px-2.5 text-xs` had appeared in three screens — the properties directory,
 * the contact import, and deal properties — and every one of them was 32px on
 * a phone, under §11's 44px floor, *"without exception"*. Sharing the variant
 * fixes all three at once and stops a fourth being written.
 *
 * A native select is also the accessible default on a phone: it opens the
 * platform picker, which is a better control than anything rebuilt in a div.
 */
import { computed } from 'vue';
import { appInputVariants } from '@/components/app/controlVariants';
import type { AppInputVariants } from '@/components/app/controlVariants';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        modelValue: string | null;
        /** Value → label, the shape every `Enum::options()` returns. */
        options: Record<string, string>;
        /** Offered above the options when the field may be left unanswered. */
        placeholder?: string | null;
        size?: AppInputVariants['size'];
        class?: string;
    }>(),
    { size: 'filter', placeholder: null },
);

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

/*
 * `''` in the DOM, `null` in the model. An empty option is how a native select
 * says "unanswered", and null is what that means to the server — a distinction
 * this screen depends on, since "nobody has said" is a different fact from
 * "Interested".
 */
const selected = computed(() => props.modelValue ?? '');

function choose(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    emit('update:modelValue', value === '' ? null : value);
}
</script>

<template>
    <select
        :value="selected"
        :class="cn(appInputVariants({ size: props.size }), props.class)"
        data-slot="app-select"
        @change="choose"
    >
        <option v-if="placeholder !== null" value="">{{ placeholder }}</option>
        <option v-for="(label, value) in options" :key="value" :value="value">
            {{ label }}
        </option>
    </select>
</template>
