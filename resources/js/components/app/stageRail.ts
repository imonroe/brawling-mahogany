/**
 * Design System §7.4's marker table, in one place (S16 · #76).
 *
 * §7.4 specifies the stage rail to the pixel because *"nothing off the shelf is
 * shaped like it"*, and the marker is the part that carries the meaning: seven
 * rows mapping a stage to a fill, a border and a glyph.
 *
 * It lives here rather than inside the component for the reason
 * `lib/states.ts` exists at all — the rail, the row and the tests all have to
 * agree about what a blocked stage looks like, and three copies of a
 * seven-row table disagree within a month.
 *
 * ## Why this is not `lib/states.ts`
 *
 * `states.ts` owns the **label and tone** of a state, and it is bound to the
 * documents by `statesMatchTheDocs.test.ts` — IA §8 lists exactly five stage
 * states and adding a sixth means editing the markdown first. This table is
 * not a sixth state. It is §7.4's *presentation* of those five, plus one row
 * for a fact that is not a state at all.
 *
 * That row is **Overridden**. A stage that completed over an overridden gate
 * is `complete`; how it got there is a separate fact, carried as `hasOverride`
 * and rendered as a different marker over the same badge. IA §8 insists an
 * override is not a kind of Met, and this is the same insistence one level up:
 * it is not a kind of *state* either, so it does not belong in the table that
 * enumerates them.
 *
 * The tone still comes from `states.ts`. Only the glyph and the geometry are
 * decided here.
 */
import { Check, Circle, Flag, Loader, Minus, ShieldAlert } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { Tone } from '@/lib/states';

/** What a rail row needs to know about itself to draw its marker. */
export type StageMarkerInput = {
    state: string;
    isMilestone: boolean;
    hasOverride: boolean;
};

export type StageMarker = {
    icon: LucideIcon;
    /**
     * The tone the marker paints with, which is not always the badge's.
     *
     * An overridden stage badges **Complete** in green — that is what happened
     * — while its marker goes amber, because §7.4 puts the override marker in
     * `state-warning` and F4.9 requires *"a visible marker on the timeline"*.
     * A green tick over a forced advance is the marker agreeing with the badge
     * and losing the one fact the marker was added to carry.
     */
    tone: Tone;
};

/**
 * §7.4's table, read top to bottom — the order matters.
 *
 * Override beats completion and beats the milestone flag, because a stage can
 * be all three and only one glyph fits. F4.9 makes it the one that must
 * survive: a milestone flag over a forced advance would announce the moment and
 * hide how it was reached, and a green tick would agree with the badge and lose
 * the only fact the marker was added to carry.
 *
 * **But only once the stage is finished**, and that is not a detail. Overriding
 * does not advance — `AdvanceWorkflow::override()` is emphatic that clearing
 * one of three blockers must not move the deal past the other two — so a stage
 * can be *active and blocked* with an overridden gate on it, which is the
 * ordinary state of a stage midway through being unstuck. Marking that one
 * Overridden would replace the live "something is still in your way" with a
 * historical note, on the one row the reader is there to act on.
 *
 * So an unfinished stage shows what it is doing now, and the override shows up
 * where it belongs: on the gate's own row, which `GateRow` already draws with
 * this same glyph.
 *
 * **And a skipped stage is not an overridden one.** IA §7 calls conflating Skip
 * with Override legally material — they are different acts with different audit
 * consequences — so a stage that was *skipped*, whatever happened to its gates
 * along the way, shows §7.4's skip marker. The one row that earns the shield is
 * a stage somebody advanced **through** by waiving a condition, which is
 * `complete` and nothing else.
 *
 * This read `['complete', 'skipped']` for a round, with a test pinning it.
 */
export function stageMarker(stage: StageMarkerInput): StageMarker {
    if (stage.hasOverride && stage.state === 'complete') {
        return { icon: ShieldAlert, tone: 'warning' };
    }

    switch (stage.state) {
        case 'complete':
            // §7.4 gives a completed milestone the flag: IA §3 makes a
            // milestone a *moment*, and a tick would draw it as one more
            // finished period.
            return { icon: stage.isMilestone ? Flag : Check, tone: 'success' };
        case 'active':
            return { icon: Loader, tone: 'info' };
        case 'blocked':
            // Amber, never red. IA §8: blocked usually means a checkbox is
            // unticked, and `states.ts` already refuses to make it danger.
            return { icon: Loader, tone: 'warning' };
        case 'skipped':
            return { icon: Minus, tone: 'neutral' };
        default:
            return { icon: Circle, tone: 'neutral' };
    }
}

/**
 * The marker's fill and border, per tone.
 *
 * Two tokens that move together (§13.2 rule 9), and no raw colour — the fill is
 * the state's `-bg` and the border and icon are the state itself.
 *
 * `neutral` is the exception, and deliberately: §7.4's table names **`muted`**
 * for Upcoming and Skipped, not `state-neutral-bg`. Both tokens exist, so this
 * is following the specification rather than working around a gap — an
 * upcoming stage is meant to recede into the page, and the neutral badge
 * background is a tint that would make twenty of them read as twenty chips.
 */
export const MARKER_TONE: Record<Tone, string> = {
    neutral: 'bg-muted border-state-neutral text-state-neutral',
    info: 'bg-state-info-bg border-state-info text-state-info',
    success: 'bg-state-success-bg border-state-success text-state-success',
    warning: 'bg-state-warning-bg border-state-warning text-state-warning',
    danger: 'bg-state-danger-bg border-state-danger text-state-danger',
};
