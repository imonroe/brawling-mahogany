<script setup lang="ts">
/**
 * How sure the model said it was — and nothing else (Design System §2.5 ·
 * PRD §4.10 F10.2 · S66).
 *
 * ## Why this is not a StatusBadge
 *
 * §2.5 is explicit, and it is the rule this component exists to hold:
 *
 * > AI extraction (S66) shows a **confidence** level alongside a **review
 * > state**. They are different vocabularies and must not share a visual
 * > treatment, or a reader will think "Low confidence" is a status.
 *
 * So: an icon and a word, no pill, no fill, no dot. A `StatusBadge` sits
 * beside this on the same card carrying `Needs Review` / `Confirmed` /
 * `Edited` / `Rejected`, and the two must not be mistakable for one another
 * at a glance by somebody scrolling. `tests/js/extractionReview.test.ts`
 * asserts the pill never comes back.
 *
 * ## Confidence is information, never permission
 *
 * Nothing here is a control, and nothing here changes what a person has to
 * do. Screen Inventory's danger note on S66 — *"design it as if someone will
 * click through it while distracted, because they will"* — is the reason a
 * 0.99 gets exactly the same Confirm press as a 0.4. A high mark is the
 * model's opinion of its own copying, not a second reader.
 *
 * ## Three bands, and the number stays off the screen
 *
 * A self-reported probability from a language model is not calibrated:
 * "0.92" invites arithmetic that the number cannot support, and two fields
 * at 0.91 and 0.93 are not meaningfully different. So the raw value is
 * banded, the band is what is drawn, and the number is available on hover
 * (`title`) for anyone who genuinely wants it.
 *
 * The middle band is deliberately **not** a state colour. §2.5 names the two
 * ends — `signal-high` in `text-state-success`, `signal-low` in
 * `text-state-danger` — and leaves the middle open; amber is spoken for by
 * *Blocked* and *Needs Review* everywhere else in the product, and spending
 * it here would put a third status-looking tint on a card whose whole problem
 * is that confidence already looks like a status.
 *
 * A null is its own band. "No confidence reported" is a fact about the
 * extraction, and drawing it as low would be inventing a number the model
 * never gave.
 */
import { Signal, SignalHigh, SignalLow, SignalMedium } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';

const props = defineProps<{
    /** 0..1, as the model reported it, or null when it reported none. */
    confidence: number | null;
}>();

/** The two cuts. Named, because a magic 0.85 in a template is a decision nobody can find. */
const HIGH = 0.85;
const MEDIUM = 0.6;

type Band = {
    key: 'high' | 'medium' | 'low' | 'unknown';
    icon: Component;
    /** Both the icon and the word — a tone moves together (§13.2 rule 9). */
    tint: string;
    label: string;
};

const band = computed<Band>((): Band => {
    const value = props.confidence;

    if (value === null || Number.isNaN(value)) {
        return {
            key: 'unknown',
            icon: Signal,
            tint: 'text-muted-foreground',
            label: 'No confidence reported',
        };
    }

    if (value >= HIGH) {
        return {
            key: 'high',
            icon: SignalHigh,
            tint: 'text-state-success',
            label: 'High confidence',
        };
    }

    if (value >= MEDIUM) {
        return {
            key: 'medium',
            icon: SignalMedium,
            tint: 'text-muted-foreground',
            label: 'Medium confidence',
        };
    }

    return {
        key: 'low',
        icon: SignalLow,
        tint: 'text-state-danger',
        label: 'Low confidence',
    };
});

/**
 * The number, for the pointer only.
 *
 * Not `formatNumber` — this is a proportion rendered as a percentage, and
 * `lib/formatters.ts` deliberately has no rule for one (IA §10 lists none).
 * Rounded to whole percent so it cannot imply more precision than it has.
 */
const exact = computed((): string | undefined =>
    props.confidence === null
        ? undefined
        : `${band.value.label} — the model reported ${Math.round(props.confidence * 100)}%. It still needs a person.`,
);
</script>

<template>
    <!--
        The visible words *are* the accessible name: "High confidence" read in
        the flow says which vocabulary this belongs to, where an `aria-label`
        over a bare icon would have to repeat it and could drift from what is
        drawn. No `role`, no pill, no background — §2.5.
    -->
    <span
        class="inline-flex items-center gap-1"
        data-slot="confidence-mark"
        :data-band="band.key"
        :title="exact"
    >
        <component
            :is="band.icon"
            class="size-3.5 shrink-0"
            :class="band.tint"
            :stroke-width="2"
            aria-hidden="true"
        />
        <span class="text-[11px] font-semibold" :class="band.tint">{{
            band.label
        }}</span>
    </span>
</template>
