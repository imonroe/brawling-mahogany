<script setup lang="ts">
/**
 * A native `<select>` at the measured control size (Design System §4.2, §11).
 *
 * Not `@/components/ui/select` — that is the shadcn listbox, which is right
 * for a picker with search or rich rows and heavy for "one of four words".
 * This is the plain element, sharing `appInputVariants` with `AppInput` so the
 * two never drift.
 *
 * §13.2 rule 6, applied: a hand-written 32px filter select had been written
 * out four times — the properties directory, the contact import, the audit
 * log, and deal properties — and every one of them was under §11's 44px floor
 * on a phone, *"without exception"*. All four now share the variant.
 *
 * **Four, not three**, and the correction is the point: the docblock claimed
 * three and that "a fourth" would be stopped, while the fourth was already
 * written in `Admin/Audit.vue`. The larger job is not done either — several
 * screens still transcribe `appInputVariants({ size: 'default' })` by hand for
 * a `<select>`, and nothing scans for it. `tests/js/controlSizes.test.ts` pins
 * this component's sizes; a scanner over `resources/js/pages` is the thing
 * that would stop the next one, and it is a follow-up rather than this PR.
 *
 * A native select is also the accessible default on a phone: it opens the
 * platform picker, which is a better control than anything rebuilt in a div.
 */
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
 * S20 depends on, since "nobody has said" is a different fact from
 * "Interested".
 *
 * Only one direction needs code. Vue's own `patchDOMProp` coerces a null
 * `:value` to `''`, so binding the model straight through already selects the
 * placeholder option; a `?? ''` here looked like the other half of the pair
 * and was doing nothing. The mapping that is real is `choose()`.
 */
function choose(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    emit('update:modelValue', value === '' ? null : value);
}
</script>

<template>
    <select
        :value="modelValue"
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
