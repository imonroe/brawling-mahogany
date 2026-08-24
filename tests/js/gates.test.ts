import { CircleAlert, CircleCheck, ShieldAlert } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GateRow from '@/components/app/GateRow.vue';
import {
    blockingGateCount,
    gateAppearance,
    gateResolutionLink,
    isOverridable,
} from '@/lib/gates';
import type { GateSummary } from '@/lib/gates';

/**
 * The requirement (gate) row, as assertions (#77, #69).
 *
 * Design System §7.4 calls this one anatomy at three densities, used by S15,
 * S16 and S23 — so what it decides is decided once, here, and the rules that
 * matter are the two an override makes possible for the first time: an
 * overridden gate must not be drawn as met, and it must not be drawn as an
 * advisory either. IA §8: *"overridden is not a kind of met"*, and it is not a
 * kind of optional.
 */
function gate(overrides: Partial<GateSummary> = {}): GateSummary {
    return {
        id: 'gate-1',
        label: 'Appraisal is back',
        gateType: 'document_present',
        isBlocking: true,
        blocksAdvance: true,
        gateState: 'unmet',
        met: false,
        explanation: 'No appraisal is attached.',
        linkTarget: {},
        ...overrides,
    };
}

describe('gateAppearance', () => {
    it('gives an unmet blocker §7.4’s circle-alert in warning', () => {
        expect(gateAppearance(gate())).toEqual({
            icon: CircleAlert,
            tone: 'warning',
        });
    });

    it('gives a met gate §7.4’s circle-check in success', () => {
        expect(
            gateAppearance(
                gate({ met: true, gateState: 'met', blocksAdvance: false }),
            ),
        ).toEqual({ icon: CircleCheck, tone: 'success' });
    });

    it('gives an overridden gate its own marker, not the met one', () => {
        // §7.4's stage rail uses `shield-alert` in `state-warning` for an
        // override, and `lib/activity.ts` uses it on the timeline. One fact,
        // one glyph, wherever it appears.
        expect(
            gateAppearance(
                gate({ gateState: 'overridden', blocksAdvance: false }),
            ),
        ).toEqual({ icon: ShieldAlert, tone: 'warning' });
    });

    it('keeps an unmet advisory quieter than a blocker', () => {
        // #77: an advisory has to be distinguishable from a blocking gate, or
        // users learn to ignore both.
        expect(
            gateAppearance(gate({ isBlocking: false, blocksAdvance: false }))
                .tone,
        ).toBe('neutral');
    });
});

describe('blockingGateCount', () => {
    /*
     * The count behind S15's warning triangle, and S23's heading.
     *
     * Every combination, because the three that are not "some blockers" are
     * the ones that read wrong: a list of pure advisories is not a stage held
     * up, and a waived blocker is the case the whole override feature creates.
     * Counting `gates.length` — which is what S15 did until round 2 — puts
     * "2 gates to clear" over a list where one is badged Overridden.
     */
    it('counts only the gates that actually stand in the way', () => {
        const blocking = gate({ isBlocking: true, blocksAdvance: true });
        const advisory = gate({ isBlocking: false, blocksAdvance: false });
        const waived = gate({
            gateState: 'overridden',
            isBlocking: true,
            blocksAdvance: false,
        });

        expect(blockingGateCount([])).toBe(0);
        expect(blockingGateCount([advisory])).toBe(0);
        expect(blockingGateCount([waived])).toBe(0);
        expect(blockingGateCount([advisory, waived])).toBe(0);
        expect(blockingGateCount([blocking])).toBe(1);
        expect(blockingGateCount([blocking, advisory, waived])).toBe(1);
        expect(blockingGateCount([blocking, blocking])).toBe(2);
    });
});

describe('gateResolutionLink', () => {
    it('sends a field gate to the properties tab', () => {
        expect(
            gateResolutionLink(
                gate({ linkTarget: { type: 'deal_field' } }),
                '/deals/d1',
            ),
        ).toBe('/deals/d1/properties');
    });

    it('resolves the shapes with no screen yet to nothing at all', () => {
        // A dead link is worse than a sentence: it teaches the reader that the
        // links do not work. `awaiting_slice` is what all three deferred
        // evaluators return, so this is the common case in Slice 2.
        //
        // `tasks` is in this list, not the one above. S17 is unbuilt, there is
        // no `deals/{deal}/tasks` route, and `DealHeader` draws that tab inert
        // for the same reason — this exact link was round 1's blocker on #158,
        // and it came back here when the two screens' copies of `linkFor()`
        // were folded into this resolver. `tests/js/routeTargets.test.ts`
        // holds it by reading the source; this holds it by asking.
        for (const type of ['tasks', 'awaiting_slice', 'gate', 'gate_config']) {
            expect(
                gateResolutionLink(gate({ linkTarget: { type } }), '/deals/d1'),
            ).toBeNull();
        }

        expect(gateResolutionLink(gate({ linkTarget: {} }), '/deals/d1')).toBe(
            null,
        );
    });
});

describe('isOverridable', () => {
    it('offers an override only on an unmet blocking gate', () => {
        expect(isOverridable(gate())).toBe(true);
    });

    it('offers none on an advisory, a met gate, or one already overridden', () => {
        expect(isOverridable(gate({ isBlocking: false }))).toBe(false);
        expect(isOverridable(gate({ met: true, gateState: 'met' }))).toBe(
            false,
        );
        expect(isOverridable(gate({ gateState: 'overridden' }))).toBe(false);
    });
});

describe('GateRow', () => {
    it('badges an overridden gate as Overridden rather than as an Advisory', () => {
        /*
         * The regression this component exists to stop.
         *
         * `StageReadiness` sorts an overridden gate into the advisory bucket,
         * because `blocksAdvance()` is `is_blocking && ! overridden`. Before
         * #77 nothing could write `overridden`, so a row reporting itself as
         * non-blocking was always genuinely advisory — and S15 drew the
         * Advisory pill from exactly that flag. The first override would have
         * had a bypassed requirement render as optional.
         */
        const wrapper = mount(GateRow, {
            props: {
                gate: gate({ gateState: 'overridden', blocksAdvance: false }),
            },
        });

        expect(wrapper.text()).toContain('Overridden');
        expect(wrapper.text()).not.toContain('Advisory');
    });

    it('badges a genuine advisory as one', () => {
        const wrapper = mount(GateRow, {
            props: {
                gate: gate({ isBlocking: false, blocksAdvance: false }),
            },
        });

        expect(wrapper.text()).toContain('Advisory');
    });

    it('boxes a blocker in warning and leaves a cleared row plain (§7.4)', () => {
        const blocking = mount(GateRow, {
            props: { gate: gate(), boxed: true },
        });

        expect(blocking.classes()).toEqual(
            expect.arrayContaining([
                'border-state-warning',
                'bg-state-warning-bg',
            ]),
        );

        const met = mount(GateRow, {
            props: {
                gate: gate({
                    met: true,
                    gateState: 'met',
                    blocksAdvance: false,
                }),
                boxed: true,
            },
        });

        expect(met.classes()).not.toContain('bg-state-warning-bg');
    });

    it('always carries the evaluator’s own sentence', () => {
        // §7.4: the sub-line stating the evidence "is what makes a refusal
        // actionable". A row that only said "Not Met" sends somebody hunting.
        const wrapper = mount(GateRow, { props: { gate: gate() } });

        expect(wrapper.text()).toContain('No appraisal is attached.');
    });
});
