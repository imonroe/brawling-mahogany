<script setup lang="ts">
/**
 * Design System §7.4's **Requirement (gate) row** — one anatomy, three
 * densities.
 *
 * §7.4 names all three: *"used in the stage card, the deal overview, and the
 * advance dialog."* Two of them exist (S15 and S23) and the third is S16, so
 * this is the component the rule asks for rather than a pattern promoted after
 * the fact.
 *
 * ```
 * [icon 15–17] [ Label 13/500 · Sub 12 muted ] [flex-1] [action or Met badge]
 * ```
 *
 * `boxed` is §7.4's advance-dialog density: *"the row is promoted to a bordered
 * box: `p-3 rounded-md border`, unmet rows getting `bg-state-warning-bg
 * border-state-warning`."* Off, it is the plain row the overview uses.
 *
 * IA §11 permits the word **Requirement** in the deal view and nowhere else;
 * the code name is Gate, which is what this file is called.
 *
 * The sub-line is always the evaluator's own sentence — "3 of 5 required tasks
 * are still open" and "no inspection report is attached" are not the same
 * sentence with different nouns, and §7.4 is explicit that the sub-line
 * carrying the evidence *"is what makes a refusal actionable"*.
 */
import { computed } from 'vue';
import { gateAppearance } from '@/lib/gates';
import type { GateSummary } from '@/lib/gates';
import type { Tone } from '@/lib/states';
import { cn } from '@/lib/utils';
import StatusBadge from './StatusBadge.vue';

const props = withDefaults(
    defineProps<{ gate: GateSummary; boxed?: boolean }>(),
    { boxed: false },
);

const appearance = computed(() => gateAppearance(props.gate));

/*
 * The tone drives the icon and, in the boxed density, the box — never one
 * without the other (Design System §13.2 rule 9: a tone is three properties
 * that move together). No raw colour appears here or anywhere else; every one
 * of these is a token.
 */
const ICON_TONE: Record<Tone, string> = {
    neutral: 'text-muted-foreground',
    info: 'text-state-info',
    success: 'text-state-success',
    warning: 'text-state-warning',
    danger: 'text-state-danger',
};

/** Only a row that is genuinely in the way gets the amber box (§7.4). */
const blocking = computed(() => props.gate.blocksAdvance);
</script>

<template>
    <div
        :class="
            cn(
                'flex items-start gap-2.5',
                boxed ? 'rounded-md border p-3' : '',
                boxed && blocking
                    ? 'border-state-warning bg-state-warning-bg'
                    : '',
            )
        "
        data-slot="gate-row"
    >
        <component
            :is="appearance.icon"
            :class="cn('mt-px size-4 shrink-0', ICON_TONE[appearance.tone])"
            aria-hidden="true"
        />

        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="flex flex-wrap items-center gap-2">
                <span
                    :class="
                        cn(
                            'text-13',
                            blocking
                                ? 'font-medium text-state-warning'
                                : 'font-medium text-foreground',
                        )
                    "
                    data-slot="gate-label"
                    >{{ gate.label }}</span
                >
                <!--
                    §11: never colour alone — every badge carries a word. An
                    advisory says so; everything else takes its label from the
                    IA §8 state table, which is the only thing that knows that
                    overridden is not a kind of met.

                    Read from `gateState` rather than from "is it in the way",
                    which is the spelling that would draw an overridden gate as
                    an Advisory — `blocksAdvance()` is `is_blocking && !
                    overridden`, so an override makes those two questions
                    disagree for the first time. Advisory is exactly: nobody
                    has met it, and it was never going to stop anybody.
                -->
                <StatusBadge
                    v-if="gate.gateState === 'unmet' && !gate.isBlocking"
                    tone="neutral"
                    label="Advisory"
                    dotless
                />
                <StatusBadge v-else domain="gate" :state="gate.gateState" />
            </span>

            <span class="text-xs text-secondary-foreground">{{
                gate.explanation
            }}</span>

            <slot name="sub" />
        </span>

        <slot name="action" />
    </div>
</template>
