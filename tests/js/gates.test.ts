import { CircleAlert, CircleCheck, ShieldAlert } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GateRow from '@/components/app/GateRow.vue';
import { gateAppearance, gateResolutionLink, isOverridable } from '@/lib/gates';
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

describe('gateResolutionLink', () => {
    it('sends a field gate to the properties tab', () => {
        expect(
            gateResolutionLink(
                gate({ linkTarget: { type: 'deal_field' } }),
                '/deals/d1',
            ),
        ).toBe('/deals/d1/properties');
    });

    it('sends a tasks gate to the tasks tab', () => {
        // `required_tasks_complete` is the gate a deal meets most, and until
        // S17 (#71) this resolved to null — there was no `deals/{deal}/tasks`
        // route, and "Go and clear it" over a 404 is worse than a sentence.
        // Now PRD §5.4's rule is true of it: the unmet gate links to the thing
        // that clears it.
        expect(
            gateResolutionLink(
                gate({ linkTarget: { type: 'tasks' } }),
                '/deals/d1',
            ),
        ).toBe('/deals/d1/tasks');
    });

    it('sends a document gate to the documents tab', () => {
        /*
         * `document_present` returned `awaiting_slice` and resolved to null
         * until S21 shipped (#98, #104) — same shape as `tasks` above, one
         * slice later. CLAUDE.md names this evaluator as owing the *"a row
         * nothing can reach"* check, and this is the assertion that discharges
         * it: a gate type with one way to be satisfied, now reachable from the
         * screen that satisfies it.
         */
        expect(
            gateResolutionLink(
                gate({
                    linkTarget: {
                        type: 'document_upload',
                        category: 'inspection_report',
                    },
                }),
                '/deals/d1',
            ),
        ).toBe('/deals/d1/documents');
    });

    it('resolves the shapes with no screen yet to nothing at all', () => {
        // A dead link is worse than a sentence: it teaches the reader that the
        // links do not work. `awaiting_slice` is what all three deferred
        // evaluators return, so this is the common case in Slice 2.
        //
        // `tasks` used to be in this list, and `document_upload` never was:
        // `document_present` returned `awaiting_slice` until its screen
        // shipped. A shape leaves here only when its screen exists —
        // this exact link was round 1's blocker on #158, and it came back when
        // the two screens' copies of `linkFor()` were folded into this
        // resolver. `tests/js/routeTargets.test.ts` holds the rule by reading
        // the source; this holds it by asking.
        for (const type of ['awaiting_slice', 'gate', 'gate_config']) {
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
