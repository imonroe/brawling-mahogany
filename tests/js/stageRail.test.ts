import { Check, Circle, Flag, Loader, Minus, ShieldAlert } from '@lucide/vue';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { MARKER_TONE, stageMarker } from '@/components/app/stageRail';

/*
 * Inertia is mocked rather than installed, the way `dealHeader.test.ts` does
 * it: `usePage` is what `usePermissions()` reads, and the Advance button on the
 * stage card is guarded by `workflow.advance` — so the mock is not scaffolding
 * here, it is the thing one of these cases is about.
 */
const permissions = {
    // What a Team Member holds. `deals.manage` is what makes the expanded
    // card's task checkboxes live (S17, #71).
    value: ['workflow.advance', 'deals.manage'] as string[],
};

/** One open, required task — the fixture both completion tests stand on. */
function oneOpenTask() {
    return {
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
    };
}

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { permissions: permissions.value } } }),
}));

const StageRail = (await import('@/components/app/StageRail.vue')).default;
const StageRow = (await import('@/components/app/StageRow.vue')).default;

type TimelineWorkflow = InstanceType<typeof StageRail>['$props']['workflow'];
type TimelineStage = InstanceType<typeof StageRow>['$props']['stage'];

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
        position: 1,
        isActive: false,
        state: 'pending',
        isMilestone: false,
        canSkip: false,
        canReopen: false,
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
            total: 3,
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

    it('leaves an unfinished stage showing what it is doing now', () => {
        /*
         * Overriding does not advance — clearing one of three blockers must not
         * move the deal past the other two — so a stage can be active, still
         * blocked, and carrying an overridden gate. That is the ordinary state
         * of a stage midway through being unstuck, and marking it Overridden
         * would replace the live "something is still in your way" with a
         * historical note on the one row the reader is there to act on.
         */
        expect(
            stageMarker(stage({ state: 'blocked', hasOverride: true })),
        ).toEqual({ icon: Loader, tone: 'warning' });

        expect(
            stageMarker(stage({ state: 'active', hasOverride: true })).icon,
        ).toBe(Loader);

        /*
         * And a skipped stage is not an overridden one, however its gates ended
         * up. IA §7 calls conflating Skip with Override legally material —
         * different acts, different audit consequences — so the skip marker
         * wins. The shield belongs to a stage somebody advanced *through* by
         * waiving a condition, which is `complete` and nothing else.
         */
        expect(
            stageMarker(stage({ state: 'skipped', hasOverride: true })).icon,
        ).toBe(Minus);

        expect(
            stageMarker(stage({ state: 'complete', hasOverride: true })).icon,
        ).toBe(ShieldAlert);
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

    it('names the row by its place in the sequence, for a reader who cannot see it', () => {
        /*
         * The marker, the connector and the row's position down the page are
         * the whole argument for a rail over a list — a stage is legible **in
         * its sequence** — and every one of them is visual. Without this a
         * screen reader hears twenty buttons named after twenty stages, in no
         * stated order, with nothing saying which one is current.
         *
         * It is also what `position` is *for*: the field shipped for a round
         * with no reader at all, which is CLAUDE.md's eager-load rule wearing
         * different clothes.
         */
        const wrapper = mount(StageRow, {
            props: {
                stage: stage({
                    position: 3,
                    name: 'Under Contract',
                    state: 'blocked',
                }),
                total: 20,
                isLast: false,
                expanded: false,
                canAdvance: false,
                advanceRefusal: null,
            },
        });

        /*
         * The sequence is **added** to the accessible name, not substituted for
         * it. An `aria-label` here replaced four pieces of visible text — the
         * stage name, the milestone pill, the meta string and the state badge —
         * so a screen reader heard the position and never heard **Blocked**,
         * the one fact this screen exists to show. §11 requires every badge to
         * carry a word.
         */
        const button = wrapper.get('button');

        expect(button.attributes('aria-label')).toBeUndefined();

        const sequence = button.get('[data-slot="stage-sequence"]');

        expect(sequence.text()).toBe('Stage 3 of 20');
        /*
         * And it is *hidden*. Without `sr-only` this renders as visible text on
         * every row of every rail — worse than the bug it replaced, and passing
         * all 191 tests, because nothing asserted the class.
         */
        expect(sequence.classes()).toContain('sr-only');
        // Everything visible is still part of what the button is called.
        expect(button.text()).toContain('Under Contract');
        expect(button.text()).toContain('Blocked');

        const current = mount(StageRow, {
            props: {
                stage: stage({
                    position: 3,
                    name: 'Under Contract',
                    isActive: true,
                }),
                total: 20,
                isLast: false,
                expanded: true,
                canAdvance: false,
                advanceRefusal: null,
            },
        });

        // Which one is current is a fact the rail draws with a bigger marker.
        expect(current.get('[data-slot="stage-sequence"]').text()).toContain(
            'current stage',
        );
    });

    it('never lets the marker hook describe something other than the glyph', () => {
        /*
         * `data-marker-state` is what a test believes the marker is saying, so
         * it has to be derived from `stageMarker` rather than re-decided beside
         * it. Re-deciding drifted the moment the rule changed: once the override
         * marker was narrowed to completed stages, an *active* overridden stage
         * drew a `Loader` under an attribute still reading `overridden`. A hook
         * that describes something other than what rendered is worse than none.
         */
        const active = row({ state: 'blocked', hasOverride: true });

        expect(
            active
                .find('[data-slot="stage-marker"]')
                .attributes('data-marker-state'),
        ).toBe('blocked');

        const done = row({ state: 'complete', hasOverride: true });

        expect(
            done
                .find('[data-slot="stage-marker"]')
                .attributes('data-marker-state'),
        ).toBe('overridden');
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

    it('hides Advance from somebody who may not advance', () => {
        /*
         * §7.3: **hidden, not disabled**. `DealHeader` and the overview's
         * workflow cards both guard this with `can('workflow.advance')`, and a
         * third caller written without it was offering an act the server
         * answers 403 to — a button that confirms an action nobody can perform.
         */
        permissions.value = ['deals.view'];

        try {
            expect(row({ isActive: true }, true).text()).not.toContain(
                'Advance stage',
            );
        } finally {
            permissions.value = ['workflow.advance', 'deals.manage'];
        }

        // And it is back for somebody who may.
        expect(row({ isActive: true }, true).text()).toContain('Advance stage');
    });

    it('replaces Advance with the workflow’s own refusal when it is not running', () => {
        const wrapper = mount(StageRow, {
            props: {
                stage: stage({ isActive: true }),
                total: 3,
                isLast: false,
                expanded: true,
                canAdvance: false,
                advanceRefusal: 'This workflow is on hold.',
            },
        });

        expect(wrapper.text()).toContain('This workflow is on hold.');
        expect(wrapper.text()).not.toContain('Advance stage');
    });

    it('mutes a skipped stage’s name, and says Milestone on the pill', () => {
        /*
         * Two lines of §7.4 the build had skipped: the Skipped row is `minus`
         * **and card text muted** — a stage nobody worked should not read at the
         * weight of the twelve that were — and the pill carries the word
         * `Milestone`, not `milestoneLabel`.
         *
         * The label is the sentence a **client** is told (IA §3) and its home is
         * the status page; rendering it here put "Under contract" in the slot
         * §7.4 reserves for the marker, beside a stage usually named the same
         * thing.
         */
        const skipped = row({ state: 'skipped' });

        expect(skipped.find('[data-slot="stage-name"]').classes()).toContain(
            'text-muted-foreground',
        );

        const running = row({ state: 'active' });

        expect(
            running.find('[data-slot="stage-name"]').classes(),
        ).not.toContain('text-muted-foreground');

        const milestone = row({
            state: 'complete',
            isMilestone: true,
        });

        expect(milestone.find('[data-slot="milestone-pill"]').text()).toBe(
            'Milestone',
        );
    });

    it('says a skipped stage carried no reason rather than saying nothing', () => {
        // IA §7 calls conflating Skip with Override legally material, and the
        // difference a reader can see is that one of them always says why.
        const wrapper = row({ state: 'skipped' }, true);

        expect(wrapper.find('[data-slot="skip-reason"]').text()).toContain(
            'No reason was recorded',
        );
    });

    it('keeps keyboard focus on the control that was pressed', async () => {
        /*
         * The collapsed card and the expanded header band are the same control
         * saying the same thing, so they are the same element. Two of them — a
         * `v-if` pair in different parents — meant every toggle destroyed the
         * focused node and dropped focus to `<body>`, which turns "open a
         * stage" into "start again from the top of the page". Twenty rows makes
         * that expensive.
         */
        const wrapper = mount(StageRow, {
            props: {
                stage: stage(),
                total: 3,
                isLast: false,
                expanded: false,
                canAdvance: false,
                advanceRefusal: null,
            },
            attachTo: document.body,
        });

        const button = wrapper.get('button');

        button.element.focus();
        expect(document.activeElement).toBe(button.element);

        await wrapper.setProps({ expanded: true });

        // Same element, still focused — and now describing itself as open.
        expect(document.activeElement).toBe(wrapper.get('button').element);
        expect(wrapper.get('button').attributes('aria-expanded')).toBe('true');

        wrapper.unmount();
    });

    it('drops the connector on the last row only', () => {
        // A line trailing past the final stage draws a step that does not exist.
        const middle = mount(StageRow, {
            props: {
                stage: stage(),
                total: 3,
                isLast: false,
                expanded: false,
                canAdvance: false,
                advanceRefusal: null,
            },
        });

        const last = mount(StageRow, {
            props: {
                stage: stage(),
                total: 3,
                isLast: true,
                expanded: false,
                canAdvance: false,
                advanceRefusal: null,
            },
        });

        expect(middle.findAll('.bg-border')).toHaveLength(1);
        expect(last.findAll('.bg-border')).toHaveLength(0);
    });

    it('completes a task from the rail, and says which one', async () => {
        /*
         * Live since S17 (#71) gave completion an endpoint. Until then this
         * row was deliberately inert — a checkbox wired to nothing is the
         * *"checkbox that selects into nothing"* S13 refused to ship — and the
         * event it emits now goes to the **task**, not to the workflow: the
         * rail is still not a second way into `AdvanceWorkflow`.
         */
        const wrapper = row({ isActive: true, tasks: oneOpenTask() }, true);

        const box = wrapper.find('input[type="checkbox"]');

        expect(box.attributes('disabled')).toBeUndefined();

        await box.setValue(true);

        expect(wrapper.emitted('complete')).toEqual([['task-1', true]]);
    });

    it('does not offer to complete a task to somebody who may not', () => {
        /*
         * PRD §4.2 F2.2's Read Only role: a broker who watches the pipeline
         * reads the checklist and does not tick it. §7.3 hides a section
         * somebody may not use; a checkbox is the one control that has to stay
         * visible either way, because its *state* is the information — so it
         * is disabled rather than dropped, and its accessible name says the
         * state rather than offering the action.
         */
        permissions.value = ['deals.view'];

        const wrapper = row({ isActive: true, tasks: oneOpenTask() }, true);

        const box = wrapper.find('input[type="checkbox"]');

        expect(box.exists()).toBe(true);
        expect(box.attributes('disabled')).toBeDefined();
    });
});

/*
 * Restored here rather than at the end of each test that narrows it. A
 * restoring line inside the test body only runs when the test *passes* — so a
 * failing assertion leaks the narrowed permissions into every test that
 * follows, and what you get is one real failure followed by a page of
 * confusing ones.
 */
afterEach(() => {
    permissions.value = ['workflow.advance', 'deals.manage'];
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

    it('tells each row how long the rail is', () => {
        /*
         * Both halves, and the first attempt only held one.
         *
         * Asserting `props('total')` holds the rail *passing* the value and
         * says nothing about the row *using* it — hard-coding `of 20` in the
         * row passed all 191, because the other test's fixture happens to have
         * twenty stages. So this reads the rendered sequence text instead, off
         * a rail with three.
         */
        const wrapper = mount(StageRail, { props: { workflow: workflow() } });

        expect(
            wrapper
                .findAll('[data-slot="stage-sequence"]')
                .map((each) => each.text()),
        ).toEqual([
            'Stage 1 of 3',
            'Stage 2 of 3, current stage',
            'Stage 3 of 3',
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

    it('follows the workflow when an advance moves it on', async () => {
        /*
         * `AdvanceStageDialog` posts with `preserveState: true`, and Inertia
         * only re-keys the page component when state is **not** preserved — so
         * advancing does not remount this rail. Seeding the open set once left
         * the *just-completed* stage expanded and the new current one shut, at
         * the one moment the reader has most reason to look at it: precisely
         * the "lose your place" failure #76 asks the screen to avoid.
         */
        const wrapper = mount(StageRail, { props: { workflow: workflow() } });

        expect(
            wrapper.findAllComponents(StageRow).map((r) => r.props('expanded')),
        ).toEqual([false, true, false]);

        // The advance lands: stage two completed, stage three is current.
        await wrapper.setProps({
            workflow: workflow({
                activeStageId: 'stage-3',
                stages: [
                    stage({ id: 'stage-1', state: 'complete', position: 1 }),
                    stage({ id: 'stage-2', state: 'complete', position: 2 }),
                    stage({
                        id: 'stage-3',
                        state: 'active',
                        isActive: true,
                        position: 3,
                    }),
                ],
            }),
        });

        const expanded = wrapper
            .findAllComponents(StageRow)
            .map((r) => r.props('expanded'));

        // The new current stage is open …
        expect(expanded[2]).toBe(true);
        // … and the row the reader already had open is still theirs. Advancing
        // adds; it does not tidy the screen up behind them.
        expect(expanded[1]).toBe(true);
    });

    it('scrolls the current stage into view, on arrival and on every advance', async () => {
        /*
         * The other half of "does not require the user to lose their place",
         * and the half that was held by nothing: deleting **both** call sites
         * left all 186 JS tests green. Opening the right row is no use if it is
         * eight hundred pixels below the fold.
         *
         * `scrollIntoView` is stubbed because jsdom does not implement it —
         * which is also why the component calls it through `?.()`, so a missing
         * implementation is not a crash.
         */
        const scrollIntoView = vi.fn();

        Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
            configurable: true,
            writable: true,
            value: scrollIntoView,
        });

        try {
            const wrapper = mount(StageRail, {
                props: { workflow: workflow() },
                attachTo: document.body,
            });

            // On arrival, so stage seventeen of twenty does not open with a
            // thousand pixels of history above it.
            expect(scrollIntoView).toHaveBeenCalledTimes(1);
            expect(scrollIntoView.mock.calls[0][0]).toMatchObject({
                block: 'center',
            });

            await wrapper.setProps({
                workflow: workflow({
                    activeStageId: 'stage-3',
                    stages: [
                        stage({
                            id: 'stage-1',
                            state: 'complete',
                            position: 1,
                        }),
                        stage({
                            id: 'stage-2',
                            state: 'complete',
                            position: 2,
                        }),
                        stage({
                            id: 'stage-3',
                            state: 'active',
                            isActive: true,
                            position: 3,
                        }),
                    ],
                }),
            });

            await nextTick();

            // And again when the workflow moves on under a preserved state.
            expect(scrollIntoView).toHaveBeenCalledTimes(2);

            wrapper.unmount();
        } finally {
            Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView');
        }
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
