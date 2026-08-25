/**
 * How a requirement (gate) row is drawn, in one place.
 *
 * Design System §7.4 specifies the **Requirement (gate) row** as one anatomy
 * at three densities — *"used in the stage card, the deal overview, and the
 * advance dialog"* — so the two questions every one of those has to answer
 * live here rather than in each: which icon and tone the row carries, and
 * where an unmet one says to go.
 *
 * IA §11 allows the word *Requirement* only inside the deal view. The code
 * name is **Gate** everywhere, which is why this file is `gates.ts`.
 *
 * ## Why this is not `states.ts`
 *
 * `lib/states.ts` answers "what does this state look like" from IA §8's
 * vocabulary, and it already owns the gate badge (`met` / `unmet` /
 * `overridden`). What it cannot answer is the row's *icon*, which §7.4 fixes
 * separately, or the link, which comes from the evaluator on the server. Those
 * are the two things a screen would otherwise invent.
 */
import { CircleAlert, CircleCheck, ShieldAlert } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { Tone } from '@/lib/states';

/**
 * One gate, as `StageReadiness` serialises it.
 *
 * `isBlocking` is the column on the row; `blocksAdvance` is whether it stands
 * in the way right now. They differ on an overridden blocking gate, which is
 * the whole reason both are carried.
 */
export type GateSummary = {
    id: string;
    label: string;
    gateType: string;
    isBlocking: boolean;
    blocksAdvance: boolean;
    gateState: string;
    met: boolean;
    explanation: string;
    linkTarget: Record<string, string>;
};

export interface GateAppearance {
    icon: LucideIcon;
    tone: Tone;
}

/**
 * §7.4's marker, and the one case the spec does not spell out.
 *
 * Met is `circle-check` in `state-success` and unmet is `circle-alert` in
 * `state-warning`; §7.4 says so. An **overridden** gate is neither, and IA §8
 * is emphatic that it must not be drawn as either — *"overridden is not a kind
 * of met"* — so it takes §7.4's own override marker, `shield-alert` in
 * `state-warning`, which is the glyph the stage rail uses for the same fact.
 */
export function gateAppearance(gate: GateSummary): GateAppearance {
    if (gate.gateState === 'overridden') {
        return { icon: ShieldAlert, tone: 'warning' };
    }

    if (gate.met) {
        return { icon: CircleCheck, tone: 'success' };
    }

    return {
        icon: CircleAlert,
        tone: gate.blocksAdvance ? 'warning' : 'neutral',
    };
}

/**
 * Where an unmet gate says to go, or null when it cannot say.
 *
 * PRD §5.4 requires that *"each unmet gate links directly to the thing that
 * clears it"*, and the evaluator is the only thing that knows what that is —
 * so `linkTarget` is passed through the server untouched and resolved to a
 * route here.
 *
 * The shapes that resolve to nothing do so deliberately. `gate` and
 * `gate_config` want screens that do not exist (marking a gate met is Slice 3;
 * the gate editor is S43), and `awaiting_slice` names an issue rather than a
 * page. **A dead link is worse than a sentence** — the sentence at least says
 * what is missing, while a link that goes nowhere teaches the reader that the
 * links do not work.
 */
export function gateResolutionLink(
    gate: GateSummary,
    dealUrl: string,
): string | null {
    switch (gate.linkTarget.type) {
        /*
         * **Only routes that exist.** `deal_field` resolves to the properties
         * tab and `tasks` to the tasks tab, both of which are built.
         *
         * `tasks` was the one that was not, for two slices: linking it
         * rendered "Go and clear it" over a 404, on the screens whose whole
         * promise is telling somebody what to do next. S17 (#71) is what
         * changed, and it is the reason PRD §5.4's rule — *"each unmet gate
         * links directly to the thing that clears it"* — is now true of the
         * gate a deal meets most, `required_tasks_complete`. It goes to the
         * tab rather than to a filtered view of one stage: the reader has to
         * be able to see the whole checklist to know what they are walking
         * into, and every group is on the page already.
         *
         * `gate` still resolves to nothing, and now deliberately rather than
         * for want of a screen: a manual gate is cleared **in place**, by the
         * Confirm button `isConfirmable()` below decides — sending somebody to
         * another page to tick one box would be the worst version of it.
         * `gate_config` and `awaiting_slice` resolve to nothing too, their
         * screens being S43 and a later slice. A dead link is worse than a
         * sentence. `tests/js/routeTargets.test.ts` holds this by reading the
         * source, because it is the second time the link has been written:
         * once on S15's own `linkFor()`, and again here when the two screens'
         * copies were folded into one.
         */
        case 'deal_field':
            return `${dealUrl}/properties`;
        case 'tasks':
            return `${dealUrl}/tasks`;
        default:
            return null;
    }
}

/**
 * Whether this gate is one somebody can simply tick (F4.8).
 *
 * A **manual confirmation** and nothing else. Every other evaluator derives
 * its answer from something real — the required tasks, a populated field, a
 * document — so a tick would be a claim rather than a cache, and the next
 * advance would overwrite it from the evaluator anyway. That is a control that
 * appears to work and silently does not.
 *
 * This is the routine path past the most common gate type in the product, and
 * for two slices it did not exist: `is_met` had no writer but the advance's
 * own cache refresh, so the only way past a manual gate was an **override** —
 * the act IA §7 reserves for a condition that should have been met and was
 * not. The audited exception was standing in for the ordinary path.
 *
 * A display concern only. `AdvanceWorkflow::confirm()` refuses each of these
 * cases in its own words, and is the only thing that decides.
 */
export function isConfirmable(gate: GateSummary): boolean {
    return gate.gateType === 'manual_confirmation' && !gate.met;
}

/**
 * Whether this gate is one an override could clear (F4.9).
 *
 * Blocking, unmet, and not already overridden. An advisory never stops an
 * advance, so overriding one would write an audit entry about a decision
 * nobody had to make — and PRD §12.2 measures the share of advances that used
 * an override, which a column full of those makes unreadable.
 *
 * A display concern only. `AdvanceWorkflow::override()` refuses each of these
 * cases in its own words, and is the only thing that decides.
 */
export function isOverridable(gate: GateSummary): boolean {
    return gate.isBlocking && !gate.met && gate.gateState !== 'overridden';
}
