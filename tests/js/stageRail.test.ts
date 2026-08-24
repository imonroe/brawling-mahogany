import { Check, Circle, Flag, Loader, Minus, ShieldAlert } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { MARKER_TONE, stageMarker } from '@/components/app/stageRail';
import StageRail from '@/components/app/StageRail.vue';
import type { TimelineWorkflow } from '@/components/app/StageRail.vue';
import StageRow from '@/components/app/StageRow.vue';
import type { TimelineStage } from '@/components/app/StageRow.vue';

/**
 * The stage rail (S16 · Design System §7.4 · #76).
 *
 * Screen Inventory calls this *"the one interaction with no obvious precedent
 * to copy"*, which is also the reason it is worth testing at this level: there
 * is no library whose behaviour we are trusting, so every rule in §7.4's marker
 * table is a rule this repository has to keep on its own.
 *
 * The case that earns its place above all the others is **overridden**. IA §8
 * lists five stage states and `overridden` is not one of them — a stage that
 * completed over an overridden gate *is* complete. So the marker and the badge
 * deliberately disagree, and a test is the only thing standing between that and
 * somebody "fixing" it.
 */
function stage(overrides: Partial<TimelineStage> = {}): TimelineStage {
    return {
        id: 'stage-1',
        name: 'Listing Preparation',
        description: null,
        position: 1,
        isActive: false,
        state: 'pending',
        isMilestone: false,
        milestoneLabel: null,
        plannedStart: null,
        plannedEnd: null,
        actualStart: null,
        actualEnd: null,
        skippedReason: null,
        hasOverride: false,
        tasks: { total: 0, complete: 0, items: [] },
        gates: [],
        gateCounts: {
            total: 0,
            blocking: 0,
            advisory: 0,
            overridden: 0,
            cleared: 0,
        },
        ...overrides,
    };
}

function row(overrides: Partial<TimelineStage> = {}, expanded = false) {
    return mount(StageRow, {
        props: {
            stage: stage(overrides),
            isLast: false,
            expanded,
            canAdvance: true,
            advanceRefusal: null,
        },
    });
}

describe('stageMarker', () => {
    it('draws §7.4’s table', () => {
        expect(stageMarker(stage({ state: 'complete' })).icon).toBe(Check);
        expect(stageMarker(stage({ state: 'active' })).icon).toBe(Loader);
        expect(stageMarker(stage({ state: 'blocked' })).icon).toBe(Loader);
        expect(stageMarker(stage({ state: 'skipped' })).icon).toBe(Minus);
        expect(stageMarker(stage({ state: 'pending' })).icon).toBe(Circle);
    });

    it('gives a completed milestone the flag, because a milestone is a moment', () => {
        // IA §3: a stage is a period, a milestone is the moment it completes.
        // A tick would draw it as one more finished period.
        expect(
            stageMarker(stage({ state: 'complete', isMilestone: true })).icon,
        ).toBe(Flag);
    });

    it('draws blocked in amber, never red', () => {
        // IA §8: blocked usually means a checkbox is unticked. A client never
        // sees it and a colleague should not read it as damage.
        expect(stageMarker(stage({ state: 'blocked' })).tone).toBe('warning');
    });

    it('lets the override marker beat completion, the milestone flag and the tick', () => {
        /*
         * A stage can be complete, a milestone, and overridden all at once, and
         * only one glyph fits. F4.9 requires *"a visible marker on the
         * timeline"* and it is the one that must survive: a flag would announce
         * the moment and hide how it was reached, and a green tick would agree
         * with the badge and lose the only fact the marker was added to carry.
         */
        const forced = stage({
            state: 'complete',
            isMilestone: true,
            hasOverride: true,
        });

        expect(stageMarker(forced)).toEqual({
            icon: ShieldAlert,
            tone: 'warning',
        });
    });

    it('uses no raw colour anywhere in the marker table', () => {
        // §13.2 rule 5. Every value is a token, and a tone is three properties
        // that move together (rule 9): fill, border and icon or none of them.
        for (const classes of Object.values(MARKER_TONE)) {
            expect(classes).not.toMatch(/#[0-9a-f]{3,8}\b/i);
            expect(classes).not.toMatch(
                /\b(bg|text|border)-(red|blue|green|amber|yellow|slate|gray|grey)-\d{2,3}\b/,
            );
            expect(classes.split(' ')).toHaveLength(3);
        }
    });
});

describe('StageRow', () => {
    it('badges the stage’s real state under an override marker', () => {
        const wrapper = row({ state: 'complete', hasOverride: true });

        // The marker says how; the badge says what happened. Both, not either.
        expect(
            wrapper
                .find('[data-slot="stage-marker"]')
                .attributes('data-marker-state'),
        ).toBe('overridden');
        expect(wrapper.find('[data-slot="status-badge"]').text()).toBe(
            'Complete',
        );
    });

    it('builds §7.4’s meta string from dates, duration and task counts', () => {
        const wrapper = row({
            actualStart: '2026-07-15T00:00:00+00:00',
            actualEnd: '2026-08-02T00:00:00+00:00',
            tasks: { total: 8, complete: 8, items: [] },
        });

        const meta = wrapper.find('[data-slot="stage-meta"]').text();

        expect(meta).toContain('18 days');
        expect(meta).toContain('8 of 8 tasks');
    });

    it('reports no duration while a stage is still running', () => {
        /*
         * A duration over a *planned* range is a forecast, and it would read as
         * a fact in a row that otherwise reports what happened. So it appears
         * only once both ends are real.
         */
        const wrapper = row({
            plannedStart: '2026-07-15T00:00:00+00:00',
            plannedEnd: '2026-08-02T00:00:00+00:00',
            actualStart: '2026-07-15T00:00:00+00:00',
        });

        expect(wrapper.find('[data-slot="stage-meta"]').text()).not.toContain(
            'days',
        );
    });

    it('prefers what happened over what was planned', () => {
        // A stage showing its plan after the fact is the screen quietly
        // disagreeing with the record.
        const wrapper = row({
            plannedStart: '2026-07-01T00:00:00+00:00',
            plannedEnd: '2026-07-10T00:00:00+00:00',
            actualStart: '2026-08-20T00:00:00+00:00',
            actualEnd: '2026-08-22T00:00:00+00:00',
        });

        const meta = wrapper.find('[data-slot="stage-meta"]').text();

        expect(meta).toContain('Aug 20');
        expect(meta).not.toContain('Jul');
    });

    it('counts requirements as cleared, not met', () => {
        /*
         * §7.4 is explicit: the count is met **plus** overridden, because
         * "1 of 1 met" over a row badged Overridden says the opposite of what
         * happened.
         */
        const wrapper = row(
            {
                isActive: true,
                gateCounts: {
                    total: 3,
                    blocking: 1,
                    advisory: 0,
                    overridden: 1,
                    cleared: 2,
                },
            },
            true,
        );

        expect(wrapper.find('[data-slot="gate-heading"]').text()).toBe(
            'Requirements to advance · 2 of 3 cleared',
        );
    });

    it('turns the requirements heading amber only while something blocks', () => {
        const blocked = row(
            {
                isActive: true,
                gateCounts: {
                    total: 2,
                    blocking: 1,
                    advisory: 0,
                    overridden: 0,
                    cleared: 1,
                },
            },
            true,
        );

        const clear = row(
            {
                isActive: true,
                gateCounts: {
                    total: 2,
                    blocking: 0,
                    advisory: 0,
                    overridden: 0,
                    cleared: 2,
                },
            },
            true,
        );

        expect(blocked.find('[data-slot="gate-heading"]').classes()).toContain(
            'text-state-warning',
        );
        expect(
            clear.find('[data-slot="gate-heading"]').classes(),
        ).not.toContain('text-state-warning');
    });

    it('offers Advance only on the active stage', () => {
        // Advancing is a workflow-level act on whatever stage is current, so a
        // button on a finished row would offer to advance something else.
        expect(row({ isActive: true }, true).text()).toContain('Advance stage');
        expect(row({ state: 'complete' }, true).text()).not.toContain(
            'Advance stage',
        );
    });

    it('replaces Advance with the workflow’s own refusal when it is not running', () => {
        const wrapper = mount(StageRow, {
            props: {
                stage: stage({ isActive: true }),
                isLast: false,
                expanded: true,
                canAdvance: false,
                advanceRefusal: 'This workflow is on hold.',
            },
        });

        expect(wrapper.text()).toContain('This workflow is on hold.');
        expect(wrapper.text()).not.toContain('Advance stage');
    });

    it('says a skipped stage carried no reason rather than saying nothing', () => {
        // IA §7 calls conflating Skip with Override legally material, and the
        // difference a reader can see is that one of them always says why.
        const wrapper = row({ state: 'skipped' }, true);

        expect(wrapper.find('[data-slot="skip-reason"]').text()).toContain(
            'No reason was recorded',
        );
    });

    it('drops the connector on the last row only', () => {
        // A line trailing past the final stage draws a step that does not exist.
        const middle = mount(StageRow, {
            props: {
                stage: stage(),
                isLast: false,
                expanded: false,
                canAdvance: false,
                advanceRefusal: null,
            },
        });

        const last = mount(StageRow, {
            props: {
                stage: stage(),
                isLast: true,
                expanded: false,
                canAdvance: false,
                advanceRefusal: null,
            },
        });

        expect(middle.findAll('.bg-border')).toHaveLength(1);
        expect(last.findAll('.bg-border')).toHaveLength(0);
    });

    it('does not offer to complete a task it cannot complete', () => {
        /*
         * S17 owns task completion and its endpoint does not exist. A checkbox
         * wired to nothing is the *"checkbox that selects into nothing"* S13
         * refused to ship.
         */
        const wrapper = row(
            {
                isActive: true,
                tasks: {
                    total: 1,
                    complete: 0,
                    items: [
                        {
                            id: 'task-1',
                            title: 'Order the sign',
                            state: 'open',
                            isRequired: true,
                            dueDate: null,
                        },
                    ],
                },
            },
            true,
        );

        const box = wrapper.find('input[type="checkbox"]');

        expect(box.exists()).toBe(true);
        expect(box.attributes('disabled')).toBeDefined();
    });
});

function workflow(overrides: Partial<TimelineWorkflow> = {}): TimelineWorkflow {
    return {
        id: 'workflow-1',
        name: 'Listing to Close',
        state: 'active',
        stateLabel: 'Active',
        isRunning: true,
        refusal: null,
        activeStageId: 'stage-2',
        canAdvance: true,
        stages: [
            stage({ id: 'stage-1', state: 'complete', position: 1 }),
            stage({
                id: 'stage-2',
                state: 'active',
                isActive: true,
                position: 2,
            }),
            stage({ id: 'stage-3', position: 3 }),
        ],
        ...overrides,
    };
}

describe('StageRail', () => {
    it('opens the active stage and leaves the rest closed', () => {
        /*
         * #76: *"a 20-stage workflow does not require the user to lose their
         * place."* The one row that needs reading is the one row that is tall.
         */
        const rows = mount(StageRail, {
            props: { workflow: workflow() },
        }).findAllComponents(StageRow);

        expect(rows.map((each) => each.props('expanded'))).toEqual([
            false,
            true,
            false,
        ]);
    });

    it('marks only the final row as last', () => {
        const rows = mount(StageRail, {
            props: { workflow: workflow() },
        }).findAllComponents(StageRow);

        expect(rows.map((each) => each.props('isLast'))).toEqual([
            false,
            false,
            true,
        ]);
    });

    it('toggles a row without closing the active one', () => {
        // Expansion is per row, so comparing a finished stage against the
        // current one does not cost the current one's detail.
        const wrapper = mount(StageRail, { props: { workflow: workflow() } });

        wrapper.findAllComponents(StageRow)[0].vm.$emit('toggle');

        return wrapper.vm.$nextTick().then(() => {
            const rows = wrapper.findAllComponents(StageRow);

            expect(rows[0].props('expanded')).toBe(true);
            expect(rows[1].props('expanded')).toBe(true);
        });
    });

    it('says once why a stopped workflow has no Advance', () => {
        // Twenty rows each explaining it separately is twenty copies of one
        // sentence.
        const wrapper = mount(StageRail, {
            props: {
                workflow: workflow({
                    isRunning: false,
                    canAdvance: false,
                    refusal: 'This workflow is on hold.',
                }),
            },
        });

        expect(wrapper.find('[data-slot="workflow-refusal"]').text()).toBe(
            'This workflow is on hold.',
        );
    });

    it('names its own workflow, so two rails are two sequences', () => {
        /*
         * F4.7 lets two workflows run at once and #76 calls that the case that
         * breaks naive designs. Two rails only read as two sequences if each
         * says whose it is.
         */
        const wrapper = mount(StageRail, {
            props: { workflow: workflow({ name: 'Pre-listing Improvements' }) },
        });

        expect(wrapper.find('[data-slot="workflow-name"]').text()).toBe(
            'Pre-listing Improvements',
        );
        expect(wrapper.text()).toContain('3 stages');
    });
});
